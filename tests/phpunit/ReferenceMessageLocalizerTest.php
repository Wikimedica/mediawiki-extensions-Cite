<?php

namespace Cite;

use LanguageQqx;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \Cite\ReferenceMessageLocalizer
 */
class ReferenceMessageLocalizerTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers ::msg
	 */
	public function testMsg() {
		$localizer = new ReferenceMessageLocalizer( new LanguageQqx() );
		$this->assertSame(
			'(cite_reference_link_prefix)',
			$localizer->msg( 'cite_reference_link_prefix' )->plain() );
	}

}
