<?php
declare( strict_types = 1 );

namespace Parsoid\Ext\Cite;

use DOMDocument;
use DOMElement;
use DOMNode;
use Parsoid\Config\Env;
use Parsoid\Config\ParsoidExtensionAPI;
use Parsoid\Ext\ExtensionTag;
use Parsoid\Html2Wt\SerializerState;
use Parsoid\Tokens\DomSourceRange;
use Parsoid\Utils\ContentUtils;
use Parsoid\Utils\DOMCompat;
use Parsoid\Utils\DOMDataUtils;
use Parsoid\Utils\DOMUtils;
use Parsoid\Utils\PHPUtils;
use Parsoid\Utils\Title;
use Parsoid\Utils\TokenUtils;
use Parsoid\Utils\WTUtils;
use stdClass;
use Wikimedia\Assert\Assert;

class References extends ExtensionTag {
	/**
	 * @param DOMNode $node
	 * @return bool
	 */
	private static function hasRef( DOMNode $node ): bool {
		$c = $node->firstChild;
		while ( $c ) {
			if ( DOMUtils::isElt( $c ) ) {
				if ( WTUtils::isSealedFragmentOfType( $c, 'ref' ) ) {
					return true;
				}
				if ( self::hasRef( $c ) ) {
					return true;
				}
			}
			$c = $c->nextSibling;
		}
		return false;
	}

	/**
	 * @param Env $env
	 * @param DOMDocument $doc
	 * @param DOMNode|null $body
	 * @param array $refsOpts
	 * @param callable|null $modifyDp
	 * @param bool $autoGenerated
	 * @return DOMElement
	 */
	private static function createReferences(
		Env $env, DOMDocument $doc, ?DOMNode $body, array $refsOpts,
		?callable $modifyDp, bool $autoGenerated = false
	): DOMElement {
		$ol = $doc->createElement( 'ol' );
		DOMCompat::getClassList( $ol )->add( 'mw-references' );
		DOMCompat::getClassList( $ol )->add( 'references' );

		if ( $body ) {
			DOMUtils::migrateChildren( $body, $ol );
		}

		// Support the `responsive` parameter
		$rrOpts = $env->getSiteConfig()->responsiveReferences();
		$responsiveWrap = !empty( $rrOpts['enabled'] );
		if ( $refsOpts['responsive'] !== null ) {
			$responsiveWrap = $refsOpts['responsive'] !== '0';
		}

		if ( $responsiveWrap ) {
			$div = $doc->createElement( 'div' );
			DOMCompat::getClassList( $div )->add( 'mw-references-wrap' );
			$div->appendChild( $ol );
			$frag = $div;
		} else {
			$frag = $ol;
		}

		if ( $autoGenerated ) {
			DOMDataUtils::addAttributes( $frag, [
					'typeof' => 'mw:Extension/references',
					'about' => $env->newAboutId()
				]
			);
		}

		$dp = DOMDataUtils::getDataParsoid( $frag );
		if ( $refsOpts['group'] ) { // No group for the empty string either
			$dp->group = $refsOpts['group'];
			$ol->setAttribute( 'data-mw-group', $refsOpts['group'] );
		}
		if ( $modifyDp ) {
			$modifyDp( $dp );
		}

		return $frag;
	}

	private static function extractRefFromNode(
		DOMElement $node, ReferencesData $refsData, ?string $referencesAboutId = null,
		?string $referencesGroup = '', array &$nestedRefsHTML = []
	): void {
		$env = $refsData->getEnv();
		$doc = $node->ownerDocument;
		$nestedInReferences = $referencesAboutId !== null;

		// This is data-parsoid from the dom fragment node that's gone through
		// dsr computation and template wrapping.
		$nodeDp = DOMDataUtils::getDataParsoid( $node );
		$typeOf = $node->getAttribute( 'typeof' );
		$isTplWrapper = preg_match( '/\bmw:Transclusion\b/', $typeOf );
		$nodeType = preg_replace( '#mw:DOMFragment/sealed/ref#', '', $typeOf, 1 );
		$content = $nodeDp->html;
		$tplDmw = $isTplWrapper ? DOMDataUtils::getDataMw( $node ) : null;

		// This is the <sup> that's the meat of the sealed fragment
		/** @var DOMElement $c */
		$c = $env->getFragment( $content )[0];
		DOMUtils::assertElt( $c );
		// All the actions that require loaded data-attributes on `c` are done
		// here so that we can quickly store those away for later.
		DOMDataUtils::visitAndLoadDataAttribs( $c );
		$cDp = DOMDataUtils::getDataParsoid( $c );
		$refDmw = DOMDataUtils::getDataMw( $c );
		if ( empty( $cDp->empty ) && self::hasRef( $c ) ) { // nested ref-in-ref
			self::processRefs( $env, $refsData, $c );
		}
		DOMDataUtils::visitAndStoreDataAttribs( $c );

		// Use the about attribute on the wrapper with priority, since it's
		// only added when the wrapper is a template sibling.
		$about = $node->hasAttribute( 'about' )
			? $node->getAttribute( 'about' )
			: $c->getAttribute( 'about' );

		// FIXME(SSS): Need to clarify semantics here.
		// If both the containing <references> elt as well as the nested <ref>
		// elt has a group attribute, what takes precedence?
		$group = $refDmw->attrs->group ?? $referencesGroup ?? '';
		$refName = $refDmw->attrs->name ?? '';
		$ref = $refsData->add( $env, $group, $refName, $about, $nestedInReferences );

		// Add ref-index linkback
		$linkBack = $doc->createElement( 'sup' );

		// FIXME: Lot of useless work for an edge case
		if ( !empty( $cDp->empty ) ) {
			// Discard wrapper if there was no input wikitext
			$content = null;
			if ( !empty( $cDp->selfClose ) ) {
				unset( $refDmw->body );
			} else {
				$refDmw->body = (object)[ 'html' => '' ];
			}
		} else {
			// If there are multiple <ref>s with the same name, but different content,
			// the content of the first <ref> shows up in the <references> section.
			// in order to ensure lossless RT-ing for later <refs>, we have to record
			// HTML inline for all of them.
			$html = '';
			$contentDiffers = false;
			if ( $ref->hasMultiples ) {
				// Use the non-pp version here since we've already stored attribs
				// before putting them in the map.
				$html = ContentUtils::toXML( $c, [ 'innerXML' => true ] );
				$contentDiffers = $html !== $ref->cachedHtml;
			}
			if ( $contentDiffers ) {
				$refDmw->body = (object)[ 'html' => $html ];
			} else {
				$refDmw->body = (object)[ 'id' => 'mw-reference-text-' . $ref->target ];
			}
		}

		DOMDataUtils::addAttributes( $linkBack, [
				'about' => $about,
				'class' => 'mw-ref',
				'id' => $nestedInReferences
					? null
					: ( $ref->name ? PHPUtils::lastItem( $ref->linkbacks ) : $ref->id ),
				'rel' => 'dc:references',
				'typeof' => $nodeType
			]
		);
		DOMDataUtils::addTypeOf( $linkBack, 'mw:Extension/ref' );
		$dataParsoid = new stdClass;
		if ( isset( $nodeDp->src ) ) {
			$dataParsoid->src = $nodeDp->src;
		}
		if ( isset( $nodeDp->dsr ) ) {
			$dataParsoid->dsr = $nodeDp->dsr;
		}
		if ( isset( $nodeDp->pi ) ) {
			$dataParsoid->pi = $nodeDp->pi;
		}
		DOMDataUtils::setDataParsoid( $linkBack, $dataParsoid );
		if ( $isTplWrapper ) {
			DOMDataUtils::setDataMw( $linkBack, $tplDmw );
		} else {
			DOMDataUtils::setDataMw( $linkBack, $refDmw );
		}

		// refLink is the link to the citation
		$refLink = $doc->createElement( 'a' );
		$title = Title::newFromText(
			$env->getPageConfig()->getTitle(),
			$env->getSiteConfig()
		);
		DOMDataUtils::addAttributes( $refLink, [
				'href' => $env->makeLink( $title ) . '#' . $ref->target,
				'style' => 'counter-reset: mw-Ref ' . $ref->groupIndex . ';',
			]
		);
		if ( $ref->group ) {
			$refLink->setAttribute( 'data-mw-group', $ref->group );
		}

		// refLink-span which will contain a default rendering of the cite link
		// for browsers that don't support counters
		$refLinkSpan = $doc->createElement( 'span' );
		$refLinkSpan->setAttribute( 'class', 'mw-reflink-text' );
		$refLinkSpan->appendChild( $doc->createTextNode(
			'[' . ( $ref->group ? $ref->group . ' ' : '' ) . $ref->groupIndex . ']'
			)
		);
		$refLink->appendChild( $refLinkSpan );
		$linkBack->appendChild( $refLink );

		if ( !$nestedInReferences ) {
			$node->parentNode->replaceChild( $linkBack, $node );
		} else {
			// We don't need to delete the node now since it'll be removed in
			// `insertReferencesIntoDOM` when all the children all cleaned out.
			array_push( $nestedRefsHTML, ContentUtils::ppToXML( $linkBack ), "\n" );
		}

		// Keep the first content to compare multiple <ref>s with the same name.
		if ( !$ref->content ) {
			$ref->content = $content;
			$ref->dir = strtolower( $refDmw->attrs->dir ?? '' );
		}
	}

	/**
	 * @param DOMElement $refsNode
	 * @param ReferencesData $refsData
	 * @param array $nestedRefsHTML
	 * @param bool $autoGenerated
	 */
	private static function insertReferencesIntoDOM(
		DOMElement $refsNode, ReferencesData $refsData, array $nestedRefsHTML, bool $autoGenerated = false
	): void {
		$env = $refsData->getEnv();
		$isTplWrapper = preg_match( '/\bmw:Transclusion\b/', $refsNode->getAttribute( 'typeof' ) );
		$dp = DOMDataUtils::getDataParsoid( $refsNode );
		$group = $dp->group ?? '';
		if ( !$isTplWrapper ) {
			$dataMw = DOMDataUtils::getDataMw( $refsNode );
			if ( !count( (array)$dataMw ) ) {
				// FIXME: This can be moved to `insertMissingReferencesIntoDOM`
				Assert::invariant( $autoGenerated, 'Expected non empty $dataMw or $autoGenerated is true' );
				$dataMw = (object)[
					'name' => 'references',
					'attrs' => new stdClass,
				];
				// Dont emit empty keys
				if ( $group ) {
					$dataMw->attrs->group = $group;
				}
				DOMDataUtils::setDataMw( $refsNode, $dataMw );
			}

			// Mark this auto-generated so that we can skip this during
			// html -> wt and so that clients can strip it if necessary.
			if ( $autoGenerated ) {
				$dataMw->autoGenerated = true;
			} elseif ( count( $nestedRefsHTML ) > 0 ) {
				$dataMw->body = (object)[ 'html' => "\n" . implode( $nestedRefsHTML ) ];
			} elseif ( empty( $dp->selfClose ) ) {
				$dataMw->body = PHPUtils::arrayToObject( [ 'html' => '' ] );
			} else {
				unset( $dataMw->body );
			}
			// @phan-suppress-next-line PhanTypeObjectUnsetDeclaredProperty
			unset( $dp->selfClose );
		}

		$refGroup = $refsData->getRefGroup( $group );

		// Deal with responsive wrapper
		if ( DOMCompat::getClassList( $refsNode )->contains( 'mw-references-wrap' ) ) {
			$rrOpts = $env->getSiteConfig()->responsiveReferences();
			if ( $refGroup && count( $refGroup->refs ) > $rrOpts['threshold'] ) {
				DOMCompat::getClassList( $refsNode )->add( 'mw-references-columns' );
			}
			$refsNode = $refsNode->firstChild;
		}

		// Remove all children from the references node
		//
		// Ex: When {{Reflist}} is reused from the cache, it comes with
		// a bunch of references as well. We have to remove all those cached
		// references before generating fresh references.
		while ( $refsNode->firstChild ) {
			$refsNode->removeChild( $refsNode->firstChild );
		}

		if ( $refGroup ) {
			foreach ( $refGroup->refs as $ref ) {
				$refGroup->renderLine( $env, $refsNode, $ref );
			}
		}

		// Remove the group from refsData
		$refsData->removeRefGroup( $group );
	}

	/**
	 * Process `<ref>`s left behind after the DOM is fully processed.
	 * We process them as if there was an implicit `<references />` tag at
	 * the end of the DOM.
	 *
	 * @param ReferencesData $refsData
	 * @param DOMNode $node
	 */
	public static function insertMissingReferencesIntoDOM(
		ReferencesData $refsData, DOMNode $node
	): void {
		$env = $refsData->getEnv();
		$doc = $node->ownerDocument;

		foreach ( $refsData->getRefGroups() as $groupName => $refsGroup ) {
			$frag = self::createReferences(
				$env,
				$doc,
				null,
				[
					// Force string cast here since in the foreach above, $groupName
					// is an array key. In that context, number-like strings are
					// silently converted to a numeric value!
					// Ex: In <ref group="2" />, the "2" becomes 2 in the foreach
					'group' => (string)$groupName,
					'responsive' => null,
				],
				function ( $dp ) use ( $env ) {
					// The new references come out of "nowhere", so to make selser work
					// properly, add a zero-sized DSR pointing to the end of the document.
					$contentLength = strlen( $env->getPageMainContent() );
					$dp->dsr = new DomSourceRange( $contentLength, $contentLength, 0, 0 );
				},
				true
			);

			// Add a \n before the <ol> so that when serialized to wikitext,
			// each <references /> tag appears on its own line.
			$node->appendChild( $doc->createTextNode( "\n" ) );
			$node->appendChild( $frag );

			self::insertReferencesIntoDOM( $frag, $refsData, [ '' ], true );
		}
	}

	/**
	 * @param Env $env
	 * @param ReferencesData $refsData
	 * @param DOMElement $node
	 */
	public static function processRefs( Env $env, ReferencesData $refsData, DOMElement $node ): void {
		$child = $node->firstChild;
		while ( $child !== null ) {
			$nextChild = $child->nextSibling;
			if ( $child instanceof DOMElement ) {
				if ( WTUtils::isSealedFragmentOfType( $child, 'ref' ) ) {
					self::extractRefFromNode( $child, $refsData );
				} elseif (
					preg_match(
						'#(?:^|\s)mw:Extension/references(?=$|\s)#',
						$child->getAttribute( 'typeof' ) ?? ''
					)
				) {
					$referencesId = $child->getAttribute( 'about' ) ?? '';
					$referencesGroup = DOMDataUtils::getDataParsoid( $child )->group ?? null;
					$nestedRefsHTML = [];
					self::processRefsInReferences(
						$refsData,
						$child,
						$referencesId,
						$referencesGroup,
						$nestedRefsHTML
					);
					self::insertReferencesIntoDOM( $child, $refsData, $nestedRefsHTML );
				} else {
					// inline media -- look inside the data-mw attribute
					if ( WTUtils::isInlineMedia( $child ) ) {
						/* -----------------------------------------------------------------
						 * FIXME(subbu): This works but feels very special-cased in 2 ways:
						 *
						 * 1. special cased to images vs. any node that might have
						 *    serialized HTML embedded in data-mw
						 * 2. special cased to global cite handling -- the general scenario
						 *    is DOM post-processors that do different things on the
						 *    top-level vs not.
						 *    - Cite needs to process these fragments in the context of the
						 *      top-level page, and has to be done in order of how the nodes
						 *      are encountered.
						 *    - DOM cleanup can be done on embedded fragments without
						 *      any page-level context and in any order.
						 *    - So, some variability here.
						 *
						 * We should be running dom.cleanup.js passes on embedded html
						 * in data-mw and other attributes. Since correctness doesn't
						 * depend on that cleanup, I am not adding more special-case
						 * code in dom.cleanup.js.
						 *
						 * Doing this more generically will require creating a DOMProcessor
						 * class and adding state to it.
						 *
						 * See T214994
						 * ----------------------------------------------------------------- */
						$dmw = DOMDataUtils::getDataMw( $child );
						$caption = $dmw->caption ?? null;
						if ( $caption ) {
							// Extract the caption HTML, build the DOM, process refs,
							// serialize to HTML, update the caption HTML.
							$captionDOM = ContentUtils::ppToDOM( $env, $caption );
							self::processRefs( $env, $refsData, $captionDOM );
							$dmw->caption = ContentUtils::ppToXML( $captionDOM, [ 'innerXML' => true ] );
						}
					}
					if ( $child->hasChildNodes() ) {
						self::processRefs( $env, $refsData, $child );
					}
				}
			}
			$child = $nextChild;
		}
	}

	/**
	 * This handles wikitext like this:
	 * ```
	 *   <references> <ref>foo</ref> </references>
	 *   <references> <ref>bar</ref> </references>
	 * ```
	 *
	 * @param ReferencesData $refsData
	 * @param DOMElement $node
	 * @param string $referencesId
	 * @param string|null $referencesGroup
	 * @param array &$nestedRefsHTML
	 */
	private static function processRefsInReferences(
		ReferencesData $refsData, DOMElement $node, string $referencesId,
		?string $referencesGroup, array &$nestedRefsHTML
	): void {
		$child = $node->firstChild;
		while ( $child !== null ) {
			$nextChild = $child->nextSibling;
			if ( $child instanceof DOMElement ) {
				if ( WTUtils::isSealedFragmentOfType( $child, 'ref' ) ) {
					self::extractRefFromNode(
						$child,
						$refsData,
						$referencesId,
						$referencesGroup,
						$nestedRefsHTML
					);
				} elseif ( $child->hasChildNodes() ) {
					self::processRefsInReferences(
						$refsData,
						$child,
						$referencesId,
						$referencesGroup,
						$nestedRefsHTML
					);
				}
			}
			$child = $nextChild;
		}
	}

	/** @inheritDoc */
	public function toDOM( ParsoidExtensionAPI $extApi, string $txt, array $extArgs ): DOMDocument {
		$doc = $extApi->parseTokenContentsToDOM(
			$extArgs,
			'',
			$txt,
			[
				'wrapperTag' => 'div',
				'pipelineOpts' => [
					'extTag' => 'references',
					'inTemplate' => $extApi->parseContext['inTemplate'] ?? null,
				],
			]
		);

		$refsOpts = TokenUtils::kvToHash( $extArgs, true ) + [
			'group' => null,
			'responsive' => null,
		];

		$docBody = DOMCompat::getBody( $doc );

		$frag = self::createReferences(
			$extApi->getEnv(),
			$doc,
			$docBody,
			$refsOpts,
			function ( $dp ) use ( $extApi ) {
				$dp->src = $extApi->getExtSource();
				// Setting redundant info on fragment.
				// $docBody->firstChild info feels cumbersome to use downstream.
				if ( $extApi->isSelfClosedExtTag() ) {
					$dp->selfClose = true;
				}
			}
		);
		DOMCompat::getBody( $doc )->appendChild( $frag );
		return $doc;
	}

	/** @inheritDoc */
	public function fromHTML(
		DOMElement $node, SerializerState $state, bool $wrapperUnmodified
	): string {
		$dataMw = DOMDataUtils::getDataMw( $node );
		if ( !empty( $dataMw->autoGenerated ) && $state->rtTestMode ) {
			// Eliminate auto-inserted <references /> noise in rt-testing
			return '';
		} else {
			$startTagSrc = $state->serializer->serializeExtensionStartTag( $node, $state );
			if ( empty( $dataMw->body ) ) {
				return $startTagSrc; // We self-closed this already.
			} else { // We self-closed this already.
				if ( is_string( $dataMw->body->html ) ) {
					$src = $state->serializer->serializeHTML(
						[
							'env' => $state->getEnv(),
							'extName' => $dataMw->name,
						],
						$dataMw->body->html
					);
					return $startTagSrc . $src . '</' . $dataMw->name . '>';
				} else {
					$state->getEnv()->log( 'error',
						'References body unavailable for: ' . DOMCompat::getOuterHTML( $node )
					);
					return ''; // Drop it!
				}
			}
		}
	}

	/** @inheritDoc */
	public function before( DOMElement $node, DOMNode $otherNode, SerializerState $state ) {
		// Serialize new references tags on a new line.
		if ( WTUtils::isNewElt( $node ) ) {
			return [ 'min' => 1, 'max' => 2 ];
		} else {
			return false;
		}
	}

	/** @inheritDoc */
	public function lintHandler(
		ParsoidExtensionAPI $extApi, DOMElement $refs, callable $defaultHandler
	): ?DOMNode {
		// Nothing to do
		//
		// FIXME: Not entirely true for scenarios where the <ref> tags
		// are defined in the references section that is itself templated.
		//
		// {{1x|<references>\n<ref name='x'><b>foo</ref>\n</references>}}
		//
		// In this example, the references tag has the right tplInfo and
		// when the <ref> tag is processed in the body of the article where
		// it is accessed, there is no relevant template or dsr info available.
		//
		// Ignoring for now.
		return $refs->nextSibling;
	}
}
