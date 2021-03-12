<?php

namespace App;

use App\Generator\AtomGenerator;
use App\Generator\ConvertGenerator;
use App\Generator\EpubGenerator;
use App\Generator\FormatGenerator;
use App\Util\Api;
use Exception;
use Krinkle\Intuition\Intuition;

class GeneratorSelector {

	/** @var string[] Format names. */
	private static $formats = [
		'epub-3',
		'epub-2',
		'htmlz',
		'mobi',
		'pdf-a4',
		'pdf-a5',
		'pdf-a6',
		'pdf-letter',
		'rtf',
		'txt',
	];

	/** @var string[] Format aliases. */
	private static $aliases = [
		'epub' => 'epub-3',
		// Returning RTF for ODT is a hack in order to not break existing URLs.
		'odt' => 'rtf',
		// A5 was more popular than A4 in 2020. T269726.
		'pdf' => 'pdf-a5',
	];

	/** @var FontProvider */
	private $fontProvider;

	/** @var Api */
	private $api;

	/** @var ConvertGenerator */
	private $convertGenerator;

	/** @var Intuition */
	private $intuition;

	public function __construct( FontProvider $fontProvider, Api $api, ConvertGenerator $convertGenerator, Intuition $intuition ) {
		$this->fontProvider = $fontProvider;
		$this->api = $api;
		$this->convertGenerator = $convertGenerator;
		$this->intuition = $intuition;
	}

	/**
	 * @return string[] All format names (including aliases).
	 */
	public static function getAllFormats(): array {
		return array_merge( self::getValidFormats(), array_keys( self::$aliases ) );
	}

	/**
	 * @return string[] Valid format names (excluding aliases).
	 */
	public static function getValidFormats(): array {
		return self::$formats;
	}

	public function getGenerator( $format ): FormatGenerator {
		// Resolve alias.
		if ( array_key_exists( $format, self::$aliases ) ) {
			$format = self::$aliases[$format];
		}
		if ( $format === 'epub-3' ) {
			return new EpubGenerator( $this->fontProvider, $this->api, $this->intuition );
		} elseif ( in_array( $format, ConvertGenerator::getSupportedTypes() ) ) {
			$this->convertGenerator->setFormat( $format );
			return $this->convertGenerator;
		} elseif ( $format === 'atom' ) {
			return new AtomGenerator();
		} else {
			throw new Exception( "The file format '$format' is unknown." );
		}
	}
}
