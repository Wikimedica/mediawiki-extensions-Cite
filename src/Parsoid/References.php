<?php
declare( strict_types = 1 );
// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment

namespace Cite\Parsoid;

use Cite\Cite;
use Cite\MarkSymbolRenderer;
use Closure;
use MediaWiki\Config\Config;
use MediaWiki\Html\HtmlHelper;
use MediaWiki\MediaWikiServices;
use stdClass;
use Wikimedia\Message\MessageValue;
use Wikimedia\Parsoid\Core\DomSourceRange;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Ext\DOMDataUtils;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Ext\ExtensionTagHandler;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Ext\PHPUtils;
use Wikimedia\Parsoid\Ext\WTUtils;
use Wikimedia\Parsoid\NodeData\DataMw;
use Wikimedia\Parsoid\NodeData\DataMwError;
use Wikimedia\Parsoid\NodeData\DataParsoid;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\RemexHtml\HTMLData;
use Wikimedia\RemexHtml\Serializer\SerializerNode;

/**
 * @license GPL-2.0-or-later
 */
class References extends ExtensionTagHandler {
	private Config $mainConfig;
	private MarkSymbolRenderer $markSymbolRenderer;

	public function __construct( Config $mainConfig ) {
		$this->mainConfig = $mainConfig;

		$this->markSymbolRenderer = MediaWikiServices::getInstance()->getService( 'Cite.MarkSymbolRenderer' );
	}

	private static function hasRef( Node $node ): bool {
		$c = $node->firstChild;
		while ( $c ) {
			if ( $c instanceof Element ) {
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

	private function createReferences(
		ParsoidExtensionAPI $extApi, DocumentFragment $domFragment,
		array $refsOpts, ?callable $modifyDp, bool $autoGenerated = false
	): Element {
		$doc = $domFragment->ownerDocument;

		$ol = $doc->createElement( 'ol' );
		DOMCompat::getClassList( $ol )->add( 'mw-references', 'references' );

		DOMUtils::migrateChildren( $domFragment, $ol );

		// Support the `responsive` parameter
		if ( $refsOpts['responsive'] !== null ) {
			$responsiveWrap = $refsOpts['responsive'] !== '0';
		} else {
			$responsiveWrap = (bool)$this->mainConfig->get( 'CiteResponsiveReferences' );
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
			// FIXME: This is very much trying to copy ExtensionHandler::onDocument
			DOMUtils::addAttributes( $frag, [
				'typeof' => 'mw:Extension/references',
				'about' => $extApi->newAboutId()
			] );
			$dataMw = new DataMw( [
				'name' => 'references',
				'attrs' => new stdClass
			] );
			// Dont emit empty keys
			if ( $refsOpts['group'] ) {
				$dataMw->attrs->group = $refsOpts['group'];
			}
			DOMDataUtils::setDataMw( $frag, $dataMw );
		}

		$dp = DOMDataUtils::getDataParsoid( $frag );
		if ( $refsOpts['group'] ) {  // No group for the empty string either
			$dp->group = $refsOpts['group'];
			$ol->setAttribute( 'data-mw-group', $refsOpts['group'] );
		}
		if ( $modifyDp ) {
			$modifyDp( $dp );
		}

		// These module namess are copied from Cite extension.
		// They are hardcoded there as well.
		$metadata = $extApi->getMetadata();
		$metadata->addModules( [ 'ext.cite.ux-enhancements' ] );
		$metadata->addModuleStyles( [ 'ext.cite.parsoid.styles', 'ext.cite.styles' ] );

		return $frag;
	}

	private function extractRefFromNode(
		ParsoidExtensionAPI $extApi, Element $node, ReferencesData $referencesData
	): void {
		$doc = $node->ownerDocument;
		$errs = [];

		// This is data-parsoid from the dom fragment node that's gone through
		// dsr computation and template wrapping.
		$nodeDp = DOMDataUtils::getDataParsoid( $node );
		$contentId = $nodeDp->html;
		$isTemplateWrapper = DOMUtils::hasTypeOf( $node, 'mw:Transclusion' );
		$templateDataMw = $isTemplateWrapper ? DOMDataUtils::getDataMw( $node ) : null;

		// This is the <sup> that's the meat of the sealed fragment
		$refFragment = $extApi->getContentDOM( $contentId )->firstChild;
		DOMUtils::assertElt( $refFragment );
		$refFragmentDp = DOMDataUtils::getDataParsoid( $refFragment );
		$refDataMw = DOMDataUtils::getDataMw( $refFragment );

		// read and validate the used attribute keys
		$attributes = $refDataMw->attrs;
		$attributesErrorMessage = $this->validateAttributeKeys( (array)$attributes );
		if ( $attributesErrorMessage ) {
			$errs[] = $attributesErrorMessage;
		}

		// read and store the attributes
		// NOTE: This will have been trimmed in Utils::getExtArgInfo()'s call
		// to TokenUtils::kvToHash() and ExtensionHandler::normalizeExtOptions()
		$refName = $attributes->name ?? '';
		$followName = $attributes->follow ?? '';
		$refDir = strtolower( $attributes->dir ?? '' );
		$extendsRef = $attributes->extends ?? null;

		// read and validate the group of the ref
		$groupName = $attributes->group ?? $referencesData->referencesGroup;
		$groupErrorMessage = $this->validateGroup( $groupName, $referencesData );
		if ( $groupErrorMessage ) {
			$errs[] = $groupErrorMessage;
		}
		$refGroup = $referencesData->getRefGroup( $groupName );

		// Use the about attribute on the wrapper with priority, since it's
		// only added when the wrapper is a template sibling.
		$about = DOMCompat::getAttribute( $node, 'about' ) ??
			DOMCompat::getAttribute( $refFragment, 'about' );
		'@phan-var string $about'; // assert that $about is non-null

		// check the attributes name and follow
		$hasName = strlen( $refName ) > 0;
		$hasFollow = strlen( $followName ) > 0;

		if ( $hasFollow ) {
			// Always wrap follows content so that there's no ambiguity
			// where to find it when roundtripping
			$followSpan = $doc->createElement( 'span' );
			DOMUtils::addTypeOf( $followSpan, 'mw:Cite/Follow' );
			$followSpan->setAttribute( 'about', $about );
			$followSpan->appendChild(
				$doc->createTextNode( ' ' )
			);
			DOMUtils::migrateChildren( $refFragment, $followSpan );
			$refFragment->appendChild( $followSpan );
		}

		$ref = null;
		$refFragmentHtml = '';
		$hasDifferingContent = false;
		$hasValidFollow = false;

		if ( $hasName ) {
			if ( $hasFollow ) {
				// Presumably, "name" has higher precedence
				$errs[] = new DataMwError( 'cite_error_ref_follow_conflicts' );
			}
			if ( isset( $refGroup->indexByName[$refName] ) ) {
				$ref = $refGroup->indexByName[$refName];
				// If there are multiple <ref>s with the same name, but different content,
				// the content of the first <ref> shows up in the <references> section.
				// in order to ensure lossless RT-ing for later <refs>, we have to record
				// HTML inline for all of them.
				if ( $ref->contentId ) {
					if ( $ref->cachedHtml === null ) {
						// @phan-suppress-next-line PhanTypeMismatchArgumentNullable False positive
						$refContent = $extApi->getContentDOM( $ref->contentId )->firstChild;
						$ref->cachedHtml = $this->normalizeRef( $extApi->domToHtml( $refContent, true, false ) );
					}
					$refFragmentHtml = $extApi->domToHtml( $refFragment, true, false );
					$hasDifferingContent = ( $this->normalizeRef( $refFragmentHtml ) !== $ref->cachedHtml );
				}
			} else {
				if ( $referencesData->inReferencesContent() ) {
					$errs[] = new DataMwError(
						'cite_error_references_missing_key',
						[ $attributes->name ]
					);
				}
			}
		} else {
			if ( $hasFollow ) {
				// This is a follows ref, so check that a named ref has already
				// been defined
				if ( isset( $refGroup->indexByName[$followName] ) ) {
					$hasValidFollow = true;
					$ref = $refGroup->indexByName[$followName];
				} else {
					// FIXME: This key isn't exactly appropriate since this
					// is more general than just being in a <references>
					// section and it's the $followName we care about, but the
					// extension to the legacy parser doesn't have an
					// equivalent key and just outputs something wacky.
					$errs[] = new DataMwError(
						'cite_error_references_missing_key',
						[ $attributes->follow ]
					);
				}
			} elseif ( $referencesData->inReferencesContent() ) {
				$errs[] = new DataMwError( 'cite_error_references_no_key' );
			}
		}

		// Process nested ref-in-ref
		//
		// Do this before possibly adding the a ref below or
		// migrating contents out of $c if we have a valid follow
		if ( empty( $refFragmentDp->empty ) && self::hasRef( $refFragment ) ) {
			if ( $hasDifferingContent ) {
				$referencesData->pushEmbeddedContentFlag();
			}
			$this->processRefs( $extApi, $referencesData, $refFragment );
			if ( $hasDifferingContent ) {
				$referencesData->popEmbeddedContentFlag();
				// If we have refs and the content differs, we need to
				// reserialize now that we processed the refs.  Unfortunately,
				// the cachedHtml we compared against already had its refs
				// processed so that would presumably never match and this will
				// always be considered a redefinition.  The implementation for
				// the legacy parser also considers this a redefinition so
				// there is likely little content out there like this :)
				$refFragmentHtml = $extApi->domToHtml( $refFragment, true, true );
			}
		}

		// Add ref-index linkback
		$linkBackSup = $doc->createElement( 'sup' );

		if ( $hasValidFollow ) {
			// Migrate content from the follow to the ref
			if ( $ref->contentId ) {
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable False positive
				$refContent = $extApi->getContentDOM( $ref->contentId )->firstChild;
				DOMUtils::migrateChildren( $refFragment, $refContent );
			} else {
				// Otherwise, we have a follow that comes after a named
				// ref without content so use the follow fragment as
				// the content
				// This will be set below with `$ref->contentId = $contentId;`
			}
		} else {
			// If we have !$ref, one might have been added in the call to
			// processRefs, ie. a self-referential ref.  We could try to look
			// it up again, but Parsoid is choosing not to support that.
			// Even worse would be if it tried to redefine itself!

			if ( !$ref ) {
				$ref = $referencesData->add( $extApi, $groupName, $refName, $extendsRef, $refDir );
			}

			// Handle linkbacks
			if ( $referencesData->inEmbeddedContent() ) {
				$ref->embeddedNodes[] = $about;
			} else {
				$ref->nodes[] = $linkBackSup;
				$ref->linkbacks[] = $ref->key . '-' . count( $ref->linkbacks );
			}
		}

		if ( isset( $attributes->dir ) ) {
			if ( $refDir !== 'rtl' && $refDir !== 'ltr' ) {
				$errs[] = new DataMwError( 'cite_error_ref_invalid_dir', [ $refDir ] );
			} elseif ( $ref->dir !== '' && $ref->dir !== $refDir ) {
				$errs[] = new DataMwError( 'cite_error_ref_conflicting_dir', [ $ref->name ] );
			}
		}

		// FIXME: At some point this error message can be changed to a warning, as Parsoid Cite now
		// supports numerals as a name without it being an actual error, but core Cite does not.
		// Follow refs do not duplicate the error which can be correlated with the original ref.
		if ( ctype_digit( $refName ) ) {
			$errs[] = new DataMwError( 'cite_error_ref_numeric_key' );
		}

		// Check for missing content, added ?? '' to fix T259676 crasher
		// FIXME: See T260082 for a more complete description of cause and deeper fix
		$hasMissingContent = ( !empty( $refFragmentDp->empty ) || trim( $refDataMw->body->extsrc ?? '' ) === '' );

		if ( $hasMissingContent ) {
			// Check for missing name and content to generate error code
			//
			// In references content, refs should be used for definition so missing content
			// is an error.  It's possible that no name is present (!hasRefName), which also
			// gets the error "cite_error_references_no_key" above, so protect against that.
			if ( $referencesData->inReferencesContent() ) {
				$errs[] = new DataMwError(
					'cite_error_empty_references_define',
					[ $attributes->name ?? '', $attributes->group ?? '' ]
				);
			} elseif ( !$hasName ) {
				if ( !empty( $refFragmentDp->selfClose ) ) {
					$errs[] = new DataMwError( 'cite_error_ref_no_key' );
				} else {
					$errs[] = new DataMwError( 'cite_error_ref_no_input' );
				}
			}

			if ( !empty( $refFragmentDp->selfClose ) ) {
				unset( $refDataMw->body );
			} else {
				// Empty the <sup> since we've serialized its children and
				// removing it below asserts everything has been migrated out
				DOMCompat::replaceChildren( $refFragment );
				$refDataMw->body = (object)[ 'html' => $refDataMw->body->extsrc ?? '' ];
			}
		} else {
			if ( $ref->contentId && !$hasValidFollow ) {
				// Empty the <sup> since we've serialized its children and
				// removing it below asserts everything has been migrated out
				DOMCompat::replaceChildren( $refFragment );
			}
			if ( $hasDifferingContent ) {
				// TODO: Since this error is being placed on the ref, the
				// key should arguably be "cite_error_ref_duplicate_key"
				$errs[] = new DataMwError(
					'cite_error_references_duplicate_key',
					[ $attributes->name ]
				);
				$refDataMw->body = (object)[ 'html' => $refFragmentHtml ];
			} else {
				$refDataMw->body = (object)[ 'id' => 'mw-reference-text-' . $ref->target ];
			}
		}

		$this->addLinkBackAttributes(
			$linkBackSup,
			$this->getLinkbackId( $ref, $referencesData, $hasValidFollow ),
			DOMCompat::getAttribute( $node, 'typeof' ),
			$about,
			$hasValidFollow
		);

		$this->addLinkBackData(
			$linkBackSup,
			$nodeDp,
			$isTemplateWrapper ? $templateDataMw : $refDataMw
		);

		// FIXME(T214241): Should the errors be added to data-mw if
		// $isTplWrapper?  Here and other calls to addErrorsToNode.
		if ( $errs ) {
			self::addErrorsToNode( $linkBackSup, $errs );
		}

		// refLink is the link to the citation
		$refLink = $doc->createElement( 'a' );
		DOMUtils::addAttributes( $refLink, [
			'href' => $extApi->getPageUri() . '#' . $ref->target,
			'style' => 'counter-reset: mw-Ref ' . $ref->groupIndex . ';',
		] );
		if ( $ref->group ) {
			$refLink->setAttribute( 'data-mw-group', $ref->group );
		}

		// refLink-span which will contain a default rendering of the cite link
		// for browsers that don't support counters
		$refLinkSpan = $doc->createElement( 'span' );
		$refLinkSpan->setAttribute( 'class', 'mw-reflink-text' );
		$refLinkSpan->appendChild( $doc->createTextNode(
			'[' . $this->markSymbolRenderer->makeLabel( $ref->group, $ref->groupIndex ) . ']'
		) );

		$refLink->appendChild( $refLinkSpan );
		$linkBackSup->appendChild( $refLink );

		// Checking if the <ref> is nested in a link
		$aParent = DOMUtils::findAncestorOfName( $node, 'a' );
		if ( $aParent !== null ) {
			// If we find a parent link, we hoist the reference up, just after the link
			// But if there's multiple references in a single link, we want to insert in order -
			// so we look for other misnested references before inserting
			$insertionPoint = $aParent->nextSibling;
			while ( $insertionPoint instanceof Element &&
				DOMCompat::nodeName( $insertionPoint ) === 'sup' &&
				!empty( DOMDataUtils::getDataParsoid( $insertionPoint )->misnested )
			) {
				$insertionPoint = $insertionPoint->nextSibling;
			}
			$aParent->parentNode->insertBefore( $linkBackSup, $insertionPoint );
			// set misnested to true and DSR to zero-sized to avoid round-tripping issues
			$dsrOffset = DOMDataUtils::getDataParsoid( $aParent )->dsr->end ?? null;
			// we created that node hierarchy above, so we know that it only contains these nodes,
			// hence there's no need for a visitor
			self::setMisnested( $linkBackSup, $dsrOffset );
			self::setMisnested( $refLink, $dsrOffset );
			self::setMisnested( $refLinkSpan, $dsrOffset );
			$parentAbout = DOMCompat::getAttribute( $aParent, 'about' );
			if ( $parentAbout !== null ) {
				$linkBackSup->setAttribute( 'about', $parentAbout );
			}
			$node->parentNode->removeChild( $node );
		} else {
			// if not, we insert it where we planned in the first place
			$node->parentNode->replaceChild( $linkBackSup, $node );
		}

		// Keep the first content to compare multiple <ref>s with the same name.
		if ( $ref->contentId === null && !$hasMissingContent ) {
			$ref->contentId = $contentId;
			// Use the dir parameter only from the full definition of a named ref tag
			$ref->dir = $refDir;
		} else {
			DOMCompat::remove( $refFragment );
			$extApi->clearContentDOM( $contentId );
		}
	}

	private function validateAttributeKeys( array $attributes ): ?DataMwError {
		static $validAttributes = [
			'group' => true,
			'name' => true,
			Cite::SUBREF_ATTRIBUTE => true,
			'follow' => true,
			'dir' => true
		];

		if ( array_diff_key( $attributes, $validAttributes ) !== [] ) {
			return new DataMwError( 'cite_error_ref_too_many_keys' );
		}

		return null;
	}

	private function validateGroup( string $groupName, ReferencesData $referencesData ): ?DataMwError {
		if (
			$referencesData->inReferencesContent() &&
			$groupName !== $referencesData->referencesGroup
		) {
			return new DataMwError(
				'cite_error_references_group_mismatch',
				[ $groupName ]
			);
		}

		return null;
	}

	/**
	 * Sets a node as misnested and its DSR as zero-width.
	 */
	private static function setMisnested( Element $node, ?int $offset ) {
		$dataParsoid = DOMDataUtils::getDataParsoid( $node );
		$dataParsoid->misnested = true;
		$dataParsoid->dsr = new DomSourceRange( $offset, $offset, null, null );
	}

	/**
	 * @param Element $node
	 * @param list<DataMwError> $errs
	 */
	private static function addErrorsToNode( Element $node, array $errs ): void {
		DOMUtils::addTypeOf( $node, 'mw:Error' );
		$dmw = DOMDataUtils::getDataMw( $node );
		$dmw->errors = is_array( $dmw->errors ?? null ) ?
			array_merge( $dmw->errors, $errs ) : $errs;
	}

	private function insertReferencesIntoDOM(
		ParsoidExtensionAPI $extApi, Element $refsNode,
		ReferencesData $refsData, bool $autoGenerated = false
	): void {
		$isTemplateWrapper = DOMUtils::hasTypeOf( $refsNode, 'mw:Transclusion' );
		$nodeDp = DOMDataUtils::getDataParsoid( $refsNode );
		$groupName = $nodeDp->group ?? '';
		$refGroup = $refsData->getRefGroup( $groupName );

		// Iterate through the ref list to back-patch typeof and data-mw error
		// information into ref for errors only known at time of references
		// insertion.  Refs in the top level dom will be processed immediately,
		// whereas embedded refs will be gathered for batch processing, since
		// we need to parse embedded content to find them.
		if ( $refGroup ) {
			$autoGeneratedWithGroup = ( $autoGenerated && $groupName !== '' );
			foreach ( $refGroup->refs as $ref ) {
				$errs = [];
				// Mark all refs that are part of a group that is autogenerated
				if ( $autoGeneratedWithGroup ) {
					$errs[] = new DataMwError(
						'cite_error_group_refs_without_references',
						[ $groupName ]
					);
				}
				// Mark all refs that are named without content
				if ( ( $ref->name !== '' ) && $ref->contentId === null ) {
					// TODO: Since this error is being placed on the ref,
					// the key should arguably be "cite_error_ref_no_text"
					$errs[] = new DataMwError(
						'cite_error_references_no_text',
						[ $ref->name ]
					);
				}
				if ( $errs ) {
					foreach ( $ref->nodes as $node ) {
						self::addErrorsToNode( $node, $errs );
					}
					foreach ( $ref->embeddedNodes as $about ) {
						$refsData->embeddedErrors[$about] = $errs;
					}
				}
			}
		}

		// Note that `$sup`s here are probably all we really need to check for
		// errors caught with `$refsData->inReferencesContent()` but it's
		// probably easier to just know that state while they're being
		// constructed.
		$nestedRefsHTML = array_map(
			static function ( Element $sup ) use ( $extApi ) {
				return $extApi->domToHtml( $sup, false, true ) . "\n";
			},
			PHPUtils::iterable_to_array( DOMCompat::querySelectorAll(
				$refsNode, 'sup[typeof~=\'mw:Extension/ref\']'
			) )
		);

		if ( !$isTemplateWrapper ) {
			$dataMw = DOMDataUtils::getDataMw( $refsNode );
			// Mark this auto-generated so that we can skip this during
			// html -> wt and so that clients can strip it if necessary.
			if ( $autoGenerated ) {
				$dataMw->autoGenerated = true;
			} elseif ( $nestedRefsHTML ) {
				$dataMw->body = (object)[ 'html' => "\n" . implode( $nestedRefsHTML ) ];
			} elseif ( empty( $nodeDp->selfClose ) ) {
				$dataMw->body = (object)[ 'html' => '' ];
			} else {
				unset( $dataMw->body );
			}
			unset( $nodeDp->selfClose );
		}

		// Deal with responsive wrapper
		if ( DOMUtils::hasClass( $refsNode, 'mw-references-wrap' ) ) {
			// NOTE: The default Cite implementation hardcodes this threshold to 10.
			// We use a configurable parameter here primarily for test coverage purposes.
			// See citeParserTests.txt where we set a threshold of 1 or 2.
			$rrThreshold = $this->mainConfig->get( 'CiteResponsiveReferencesThreshold' ) ?? 10;
			if ( $refGroup && count( $refGroup->refs ) > $rrThreshold ) {
				DOMCompat::getClassList( $refsNode )->add( 'mw-references-columns' );
			}
			$refsNode = $refsNode->firstChild;
		}
		DOMUtils::assertElt( $refsNode );

		// Remove all children from the references node
		//
		// Ex: When {{Reflist}} is reused from the cache, it comes with
		// a bunch of references as well. We have to remove all those cached
		// references before generating fresh references.
		DOMCompat::replaceChildren( $refsNode );

		if ( $refGroup ) {
			foreach ( $refGroup->refs as $ref ) {
				$refGroup->renderLine( $extApi, $refsNode, $ref );
			}
		}

		// Remove the group from refsData
		$refsData->removeRefGroup( $groupName );
	}

	/**
	 * Process `<ref>`s left behind after the DOM is fully processed.
	 * We process them as if there was an implicit `<references />` tag at
	 * the end of the DOM.
	 *
	 * @param ParsoidExtensionAPI $extApi
	 * @param ReferencesData $referencesData
	 * @param Node $node
	 */
	public function insertMissingReferencesIntoDOM(
		ParsoidExtensionAPI $extApi, ReferencesData $referencesData, Node $node
	): void {
		$doc = $node->ownerDocument;
		foreach ( $referencesData->getRefGroups() as $groupName => $refsGroup ) {
			$domFragment = $doc->createDocumentFragment();
			$refFragment = $this->createReferences(
				$extApi,
				$domFragment,
				[
					// Force string cast here since in the foreach above, $groupName
					// is an array key. In that context, number-like strings are
					// silently converted to a numeric value!
					// Ex: In <ref group="2" />, the "2" becomes 2 in the foreach
					'group' => (string)$groupName,
					'responsive' => null,
				],
				static function ( $dp ) use ( $extApi ) {
					// The new references come out of "nowhere", so to make selser work
					// properly, add a zero-sized DSR pointing to the end of the document.
					$content = $extApi->getPageConfig()->getRevisionContent()->getContent( 'main' );
					$contentLength = strlen( $content );
					$dp->dsr = new DomSourceRange( $contentLength, $contentLength, 0, 0 );
				},
				true
			);

			// Add a \n before the <ol> so that when serialized to wikitext,
			// each <references /> tag appears on its own line.
			$node->appendChild( $doc->createTextNode( "\n" ) );
			$node->appendChild( $refFragment );

			$this->insertReferencesIntoDOM( $extApi, $refFragment, $referencesData, true );
		}
	}

	private function processEmbeddedRefs(
		ParsoidExtensionAPI $extApi, ReferencesData $refsData, string $str
	): string {
		$domFragment = $extApi->htmlToDom( $str );
		$this->processRefs( $extApi, $refsData, $domFragment );
		return $extApi->domToHtml( $domFragment, true, true );
	}

	public function processRefs(
		ParsoidExtensionAPI $extApi, ReferencesData $refsData, Node $node
	): void {
		$child = $node->firstChild;
		while ( $child !== null ) {
			$nextChild = $child->nextSibling;
			if ( $child instanceof Element ) {
				if ( WTUtils::isSealedFragmentOfType( $child, 'ref' ) ) {
					$this->extractRefFromNode( $extApi, $child, $refsData );
				} elseif ( DOMUtils::hasTypeOf( $child, 'mw:Extension/references' ) ) {
					if ( !$refsData->inReferencesContent() ) {
						$refsData->referencesGroup =
							DOMDataUtils::getDataParsoid( $child )->group ?? '';
					}
					$refsData->pushEmbeddedContentFlag( 'references' );
					if ( $child->hasChildNodes() ) {
						$this->processRefs( $extApi, $refsData, $child );
					}
					$refsData->popEmbeddedContentFlag();
					if ( !$refsData->inReferencesContent() ) {
						$refsData->referencesGroup = '';
						$this->insertReferencesIntoDOM( $extApi, $child, $refsData, false );
					}
				} else {
					$refsData->pushEmbeddedContentFlag();
					// Look for <ref>s embedded in data attributes
					$extApi->processAttributeEmbeddedHTML( $child,
						function ( string $html ) use ( $extApi, $refsData ) {
							return $this->processEmbeddedRefs( $extApi, $refsData, $html );
						}
					);
					$refsData->popEmbeddedContentFlag();
					if ( $child->hasChildNodes() ) {
						$this->processRefs( $extApi, $refsData, $child );
					}
				}
			}
			$child = $nextChild;
		}
	}

	/**
	 * Traverse into all the embedded content and mark up the refs in there
	 * that have errors that weren't known before the content was serialized.
	 *
	 * Some errors are only known at the time when we're inserting the
	 * references lists, at which point, embedded content has already been
	 * serialized and stored, so we no longer have live access to it.  We
	 * therefore map about ids to errors for a ref at that time, and then do
	 * one final walk of the dom to peak into all the embedded content and
	 * mark up the errors where necessary.
	 *
	 * @param ParsoidExtensionAPI $extApi
	 * @param ReferencesData $refsData
	 * @param Node $node
	 */
	public function addEmbeddedErrors(
		ParsoidExtensionAPI $extApi, ReferencesData $refsData, Node $node
	): void {
		$processEmbeddedErrors = function ( string $html ) use ( $extApi, $refsData ) {
			// Similar to processEmbeddedRefs
			$domFragment = $extApi->htmlToDom( $html );
			$this->addEmbeddedErrors( $extApi, $refsData, $domFragment );
			return $extApi->domToHtml( $domFragment, true, true );
		};
		$child = $node->firstChild;
		while ( $child !== null ) {
			$nextChild = $child->nextSibling;
			if ( $child instanceof Element ) {
				$extApi->processAttributeEmbeddedHTML(
					$child, $processEmbeddedErrors
				);
				if ( DOMUtils::hasTypeOf( $child, 'mw:Extension/ref' ) ) {
					$about = DOMCompat::getAttribute( $child, 'about' );
					'@phan-var string $about'; // assert $about is non-null
					$errs = $refsData->embeddedErrors[$about] ?? null;
					if ( $errs ) {
						self::addErrorsToNode( $child, $errs );
					}
				}
				if ( $child->hasChildNodes() ) {
					$this->addEmbeddedErrors( $extApi, $refsData, $child );
				}
			}
			$child = $nextChild;
		}
	}

	/** @inheritDoc */
	public function sourceToDom(
		ParsoidExtensionAPI $extApi, string $txt, array $extArgs
	): DocumentFragment {
		$domFragment = $extApi->extTagToDOM(
			$extArgs,
			$txt,
			[
				'parseOpts' => [ 'extTag' => 'references' ],
			]
		);

		$refsOpts = $extApi->extArgsToArray( $extArgs ) + [
			'group' => null,
			'responsive' => null,
		];

		// Detect invalid parameters on the references tag
		$knownAttributes = [ 'group', 'responsive' ];
		foreach ( $refsOpts as $key => $value ) {
			if ( !in_array( strtolower( (string)$key ), $knownAttributes, true ) ) {
				$extApi->pushError( 'cite_error_references_invalid_parameters' );
				$error = new MessageValue( 'cite_error_references_invalid_parameters' );
				break;
			}
		}

		$frag = $this->createReferences(
			$extApi,
			$domFragment,
			$refsOpts,
			static function ( $dp ) use ( $extApi ) {
				$dp->src = $extApi->extTag->getSource();
				// Setting redundant info on fragment.
				// $docBody->firstChild info feels cumbersome to use downstream.
				if ( $extApi->extTag->isSelfClosed() ) {
					$dp->selfClose = true;
				}
			}
		);
		$domFragment->appendChild( $frag );

		if ( isset( $error ) ) {
			$errorFragment = ErrorUtils::renderParsoidErrorSpan( $extApi, $error );
			// we're pushing it after the reference block while it tends to be before in legacy (error + rerender)
			$extApi->addTrackingCategory( 'cite-tracking-category-cite-diffing-error' );
			$frag->appendChild( $errorFragment );
		}

		return $domFragment;
	}

	/** @inheritDoc */
	public function processAttributeEmbeddedHTML(
		ParsoidExtensionAPI $extApi, Element $elt, Closure $proc
	): void {
		$dataMw = DOMDataUtils::getDataMw( $elt );
		if ( isset( $dataMw->body->html ) ) {
			$dataMw->body->html = $proc( $dataMw->body->html );
		}
	}

	/** @inheritDoc */
	public function domToWikitext(
		ParsoidExtensionAPI $extApi, Element $node, bool $wrapperUnmodified
	) {
		$dataMw = DOMDataUtils::getDataMw( $node );
		// Autogenerated references aren't considered erroneous (the extension to the legacy
		// parser also generates them) and are not suppressed when serializing because apparently
		// that's the behaviour Parsoid clients want.  However, autogenerated references *with
		// group attributes* are errors (the legacy extension doesn't generate them at all) and
		// are suppressed when serialized since we considered them an error while parsing and
		// don't want them to persist in the content.
		if ( !empty( $dataMw->autoGenerated ) && ( $dataMw->attrs->group ?? '' ) !== '' ) {
			return '';
		} else {
			$startTagSrc = $extApi->extStartTagToWikitext( $node );
			if ( empty( $dataMw->body ) ) {
				return $startTagSrc; // We self-closed this already.
			} else {
				if ( isset( $dataMw->body->html ) ) {
					$src = $extApi->htmlToWikitext(
						[ 'extName' => $dataMw->name ],
						$dataMw->body->html
					);
					return $startTagSrc . $src . '</' . $dataMw->name . '>';
				} else {
					$extApi->log( 'error',
						'References body unavailable for: ' . DOMCompat::getOuterHTML( $node )
					);
					return ''; // Drop it!
				}
			}
		}
	}

	/** @inheritDoc */
	public function lintHandler(
		ParsoidExtensionAPI $extApi, Element $refs, callable $defaultHandler
	): bool {
		$dataMw = DOMDataUtils::getDataMw( $refs );
		if ( isset( $dataMw->body->html ) ) {
			$fragment = $extApi->htmlToDom( $dataMw->body->html );
			$defaultHandler( $fragment );
		}
		return true;
	}

	/** @inheritDoc */
	public function diffHandler(
		ParsoidExtensionAPI $extApi, callable $domDiff, Element $origNode,
		Element $editedNode
	): bool {
		$origDataMw = DOMDataUtils::getDataMw( $origNode );
		$editedDataMw = DOMDataUtils::getDataMw( $editedNode );

		if ( isset( $origDataMw->body->html ) && isset( $editedDataMw->body->html ) ) {
			$origFragment = $extApi->htmlToDom(
				$origDataMw->body->html, $origNode->ownerDocument,
				[ 'markNew' => true ]
			);
			$editedFragment = $extApi->htmlToDom(
				$editedDataMw->body->html, $editedNode->ownerDocument,
				[ 'markNew' => true ]
			);
			return call_user_func( $domDiff, $origFragment, $editedFragment );
		}

		// FIXME: Similar to DOMDiff::subtreeDiffers, maybe $editNode should
		// be marked as inserted to avoid losing any edits, at the cost of
		// more normalization

		return false;
	}

	private function addLinkBackData(
		Element $linkBackSup,
		DataParsoid $nodeDp,
		?DataMw $dataMw
	): void {
		$dataParsoid = new DataParsoid();
		if ( isset( $nodeDp->src ) ) {
			$dataParsoid->src = $nodeDp->src;
		}
		if ( isset( $nodeDp->dsr ) ) {
			$dataParsoid->dsr = $nodeDp->dsr;
		}
		if ( isset( $nodeDp->pi ) ) {
			$dataParsoid->pi = $nodeDp->pi;
		}
		DOMDataUtils::setDataParsoid( $linkBackSup, $dataParsoid );
		DOMDataUtils::setDataMw( $linkBackSup, $dataMw );
	}

	private function addLinkBackAttributes(
		Element $linkBackSup,
		?string $id,
		?string $typeof,
		?string $about,
		bool $hasValidFollow
	): void {
		$class = 'mw-ref reference';
		if ( $hasValidFollow ) {
			$class .= ' mw-ref-follow';
		}

		DOMUtils::addAttributes( $linkBackSup, [
			'about' => $about,
			'class' => $class,
			'id' => $id,
			'rel' => 'dc:references',
			'typeof' => $typeof,
		] );
		DOMUtils::removeTypeOf( $linkBackSup, 'mw:DOMFragment/sealed/ref' );
		DOMUtils::addTypeOf( $linkBackSup, 'mw:Extension/ref' );
	}

	private function getLinkbackId(
		?RefGroupItem $ref,
		ReferencesData $refsData,
		bool $hasValidFollow
	): ?string {
		if ( $refsData->inEmbeddedContent() || $hasValidFollow ) {
			return null;
		}

		$lastLinkBack = $ref->linkbacks[count( $ref->linkbacks ) - 1] ?? null;
		return $ref->name ? $lastLinkBack : $ref->id;
	}

	/**
	 * This method removes the data-parsoid and about attributes from the HTML string passed in parameters, so
	 * that it doesn't interfere for the comparison of identical references.
	 * Remex does not implement the removal of "foreign" attributes, which means that these attributes cannot be
	 * removed on math and svg elements (T380977), and that trying to do so crashes the rendering.
	 * To avoid this, we only apply the normalization to nodes in the HTML namespace. This is wider than the
	 * exact definition of foreign attributes in Remex, but the other integration points of non-foreign content
	 * would be embedded in foreign content anyway - whose data-parsoid/about attributes would not be stripped
	 * anyway, so there's no need to process them.
	 * This means that identical references containing math or svg tags will be detected as being different.
	 * This is probably a rare enough corner case. If it is not, implementing the handling of foreign attributes
	 * (as started in Idf30b3afa00743fd78b015ff080cac29e1673f09) is a path to re-consider.
	 */
	private function normalizeRef( string $s ): string {
		return HtmlHelper::modifyElements( $s,
			static function ( SerializerNode $node ): bool {
				return $node->namespace == HTMLData::NS_HTML
					&& ( isset( $node->attrs['data-parsoid'] ) || isset( $node->attrs['about'] ) );
			},
			static function ( SerializerNode $node ): SerializerNode {
				unset( $node->attrs['data-parsoid'] );
				unset( $node->attrs['about'] );
				return $node;
			}
		);
	}

}
