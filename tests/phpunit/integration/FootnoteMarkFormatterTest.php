<?php

namespace Cite\Tests\Integration;

use Cite\AlphabetsProvider;
use Cite\AnchorFormatter;
use Cite\FootnoteMarkFormatter;
use Cite\MarkSymbolRenderer;
use Cite\ReferenceMessageLocalizer;
use Cite\Tests\TestUtils;
use MediaWiki\Config\Config;
use MediaWiki\Message\Message;
use MediaWiki\Parser\Parser;

/**
 * @covers \Cite\FootnoteMarkFormatter
 * @license GPL-2.0-or-later
 */
class FootnoteMarkFormatterTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideLinkRef
	 */
	public function testLinkRef( array $ref, string $expectedOutput ) {
		$anchorFormatter = $this->createMock( AnchorFormatter::class );
		$anchorFormatter->method( 'jumpLink' )->willReturnArgument( 0 );
		$anchorFormatter->method( 'backLinkTarget' )->willReturnCallback(
			static fn ( ...$args ) => implode( '+', $args )
		);
		$messageLocalizer = $this->createMock( ReferenceMessageLocalizer::class );
		$messageLocalizer->method( 'localizeSeparators' )->willReturnArgument( 0 );
		$messageLocalizer->method( 'localizeDigits' )->willReturnArgument( 0 );
		$messageLocalizer->method( 'msg' )->willReturnCallback(
			function ( $key, ...$params ) {
				$customizedGroup = $key === 'cite_link_label_group-foo';
				$msg = $this->createMock( Message::class );
				$msg->method( 'isDisabled' )->willReturn( !$customizedGroup );
				$msg->method( 'plain' )->willReturn( $customizedGroup
					? 'a b c'
					: "($key|" . implode( '|', $params ) . ')'
				);
				return $msg;
			}
		);
		$markSymbolRenderer = new MarkSymbolRenderer(
			$messageLocalizer,
			$this->createNoOpMock( AlphabetsProvider::class ),
			$this->createNoOpMock( Config::class )
		);
		$mockParser = $this->createNoOpMock( Parser::class, [ 'recursiveTagParse' ] );
		$mockParser->method( 'recursiveTagParse' )->willReturnArgument( 0 );
		$formatter = new FootnoteMarkFormatter(
			$anchorFormatter,
			$markSymbolRenderer,
			$messageLocalizer
		);

		$ref = TestUtils::refFromArray( $ref );
		$output = $formatter->linkRef( $mockParser, $ref );
		$this->assertSame( $expectedOutput, $output );
	}

	public static function provideLinkRef() {
		return [
			'Default label' => [
				[
					'name' => null,
					'group' => '',
					'number' => 50003,
					'key' => 50004,
				],
				'(cite_reference_link|50004+|50004|50003)'
			],
			'Default label, named group' => [
				[
					'name' => null,
					'group' => 'bar',
					'number' => 3,
					'key' => 4,
				],
				'(cite_reference_link|4+|4|bar 3)'
			],
			'Custom label' => [
				[
					'name' => null,
					'group' => 'foo',
					'number' => 3,
					'key' => 4,
				],
				'(cite_reference_link|4+|4|c)'
			],
			'Custom label overrun' => [
				[
					'name' => null,
					'group' => 'foo',
					'number' => 10,
					'key' => 4,
				],
				'(cite_reference_link|4+|4|foo 10)'
			],
			'Named ref' => [
				[
					'name' => 'a',
					'group' => '',
					'number' => 3,
					'key' => 4,
					'count' => 1,
				],
				'(cite_reference_link|a+4-0|a-4|3)'
			],
			'Named ref reused' => [
				[
					'name' => 'a',
					'group' => '',
					'number' => 3,
					'key' => 4,
					'count' => 50002,
				],
				'(cite_reference_link|a+4-50001|a-4|3)'
			],
			'Subreference' => [
				[
					'name' => null,
					'group' => '',
					'number' => 3,
					'key' => 4,
					'extends' => 'b',
					'extendsIndex' => 50002,
				],
				'(cite_reference_link|4+|4|3.50002)'
			],
		];
	}
}
