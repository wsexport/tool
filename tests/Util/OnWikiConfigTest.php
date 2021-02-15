<?php

namespace App\Tests\Util;

use App\Util\Api;
use App\Util\OnWikiConfig;
use Krinkle\Intuition\Intuition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;

class OnWikiConfigTest extends TestCase {

	/**
	 * @covers \App\Util\OnWikiConfig::getDefaultFont()
	 */
	public function testGetDefaultFont() {
		/** @var Api $api */
		$api = $this->createMock( Api::class );

		// Set up getDomainName() to return whatever was given to setLang(), for ease of testing.
		$lang = null;
		$api->expects( $this->exactly( 3 ) )->method( 'setLang' )->willReturnCallback(
			function ( $arg ) use ( &$lang ) {
				$lang = $arg;
			}
		);
		$api->expects( $this->exactly( 3 ) )
			->method( 'getDomainName' )
			->willReturnCallback( function () use ( &$lang ) {
				return $lang;
			} );

		// Set up the mock JSON string responses.
		$api->expects( $this->exactly( 3 ) )
			->method( 'get' )
			->will( $this->returnValueMap( [
				[ 'https://xxx/w/index.php?title=MediaWiki:WS_Export.json&action=raw&ctype=application/json', '', ],
				[ 'https://en/w/index.php?title=MediaWiki:WS_Export.json&action=raw&ctype=application/json', '', ],
				[ 'https://beta/w/index.php?title=MediaWiki:WS_Export.json&action=raw&ctype=application/json', '{"defaultFont": "Beta Font"}', ],
			] ) );

		$onWikiConfig = new OnWikiConfig( $api, new NullAdapter(), new Intuition() );

		// Invalid language.
		$this->assertSame( '', $onWikiConfig->getDefaultFont( 'xxx' ) );
		// English has no default font set.
		$this->assertSame( '', $onWikiConfig->getDefaultFont( 'en' ) );
		// Beta has a default.
		$this->assertSame( 'Beta Font', $onWikiConfig->getDefaultFont( 'beta' ) );
	}

}
