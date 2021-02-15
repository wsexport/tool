<?php

namespace App\Tests\BookCreator;

use App\BookCreator;
use App\EpubCheck\EpubCheck;
use App\FontProvider;
use App\Generator\ConvertGenerator;
use App\GeneratorSelector;
use App\Util\Api;
use App\Util\OnWikiConfig;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers BookCreator
 * @group integration
 */
class BookCreatorIntegrationTest extends KernelTestCase {

	/** @var FontProvider */
	private $fontProvider;

	/** @var Api */
	private $api;

	/** @var GeneratorSelector */
	private $generatorSelector;

	/** @var EpubCheck */
	private $epubCheck;

	public function setUp(): void {
		self::bootKernel();
		$this->fontProvider = new FontProvider( new ArrayAdapter(), self::$container->get( OnWikiConfig::class ) );
		$this->api = self::$container->get( Api::class );
		$convertGenerator = new ConvertGenerator( $this->fontProvider, $this->api, 10 );
		$this->generatorSelector = new GeneratorSelector( $this->fontProvider, $this->api, $convertGenerator );
		$this->epubCheck = self::$container->get( EpubCheck::class );
	}

	public function bookProvider() {
		return [
			[ 'Around_the_Moon', 'en' ],
			[ 'Fables_de_La_Fontaine/édition_1874/Le_Philosophe_Scythe', 'fr' ]
		 ];
	}

	/**
	 * @dataProvider bookProvider
	 * @group exclude-from-ci
	 */
	public function testCreateBookEpub2( $title, $language ) {
		$epubFile = $this->createBook( $title, $language, 'epub-2' );
		$this->checkEpub( $epubFile );
	}

	 /**
	  * @dataProvider bookProvider
	  */
	 public function testCreateBookEpub3( $title, $language ) {
		 $epubFile = $this->createBook( $title, $language, 'epub-3' );
		 $this->checkEpub( $epubFile );
	 }

	 /**
	  * @dataProvider bookProvider
	  */
	 public function testCreateBookMobi( $title, $language ) {
		 $this->createBook( $title, $language, 'mobi' );
	 }

	private function createBook( $title, $language, $format ) {
		$this->api->setLang( $language );
		$creator = BookCreator::forApi( $this->api, $format, [ 'credits' => false ], $this->generatorSelector );
		$creator->create( $title );
		$this->assertFileExists( $creator->getFilePath() );
		$this->assertNotNull( $creator->getBook() );
		return $creator->getFilePath();
	}

	/**
	 * Check an epub and fail if there are any errors.
	 * @param string $file
	 */
	private function checkEpub( string $file ) {
		foreach ( $this->epubCheck->check( $file ) as $result ) {
			if ( $result->isError() ) {
				$msg = $result->getMessage();
				if ( $result->getLocations() ) {
					$msg .= ' -- Location 1 of ' . count( $result->getLocations() ) . ': ' . $result->getLocations()[0];
				}
				$this->fail( $msg );
			}
		}
	}
}
