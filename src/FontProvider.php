<?php

namespace App;

use App\Util\OnWikiConfig;
use DateInterval;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @author Thomas Pellissier Tanon
 * @copyright 2017-2019 Thomas Pellissier Tanon
 * @license GPL-2.0-or-later
 */

/**
 * provide data about fonts
 */
class FontProvider {

	/** @var CacheInterface */
	private $cache;

	/** @var string[][]|null */
	private $data;

	/** @var string[] Runtime cache of variants of font names. */
	private $names;

	/** @var OnWikiConfig */
	private $onWikiConfig;

	public function __construct( CacheInterface $cache, OnWikiConfig $config ) {
		$this->cache = $cache;
		$this->names = [];
		$this->onWikiConfig = $config;
	}

	/**
	 * @param string $lang
	 * @return string
	 */
	public function getDefault( string $lang ): string {
		return $this->onWikiConfig->getDefaultFont( $lang );
	}

	/**
	 * Return data about a font.
	 * @param string|null $name Font family name.
	 * @return string[][][]|null Array with 'styles' and 'langs' keys, the former containing style names (e.g. Bold) to
	 * font filenames and the latter an array of language codes supported by the font. Or null if no info found.
	 */
	public function getOne( ?string $name ): ?array {
		if ( $name === '' || $name === null ) {
			return null;
		}

		$name = $this->resolveName( $name );

		if ( isset( $this->data[ $name ] ) ) {
			return $this->data[ $name ];
		}

		$this->data = $this->getAll();

		return $this->data[ $name ] ?? null;
	}

	/**
	 * Get a list of all locally installed fonts.
	 * @return string[][][][] Key is the font name, value is an array with 'styles' and 'langs' keys.
	 */
	public function getAll(): array {
		return $this->cache->get( 'fonts', function ( ItemInterface $item ) {
			$item->expiresAfter( new DateInterval( 'P7D' ) );
			$fontData = [];
			// Get semicolon-separated parts of font information.
			$cmd = 'fc-list --format "%{file};%{family};%{slant};%{weight};%{lang};%{fontformat}\n"';
			$process = Process::fromShellCommandline( $cmd );
			$process->mustRun();
			$output = $process->getOutput();
			// Format the output into a single array.
			$lines = array_filter( explode( "\n", $output ) );
			$formats = [];
			foreach ( $lines as $line ) {
				$parts = str_getcsv( $line, ';' );
				if ( count( $parts ) !== 6 ) {
					// Guard against malformed results.
					continue;
				}
				$file = $parts[0];
				$family = $this->getFamilyName( $parts[1] );
				$slant = $parts[2];
				$weight = $parts[3];
				$langs = explode( '|', $parts[4] );
				$format = $parts[5];
				if ( !isset( $fontData[ $family ] ) ) {
					$fontData[ $family ] = [
						'styles' => [],
						'langs' => $langs,
					];
				}

				// Some fonts have both ttf/TrueType and otf/OpenType formats; remove the latter if there are both.
				if ( !isset( $formats[ $family . $slant . $weight ] ) || $formats[ $family . $slant . $weight ] === 'CFF' ) {
					$formats[ $family . $slant . $weight ] = $format;
				} else {
					continue;
				}

				$fontData[ $family ]['styles'][ $slant . $weight ] = [
					'file' => $file,
					'slant' => $slant,
					'weight' => $weight,
				];
				ksort( $fontData[ $family ]['styles'] );
			}
			ksort( $fontData );
			return $fontData;
		} );
	}

	/**
	 * Get the family name. For fonts with alternative
	 * names return the first one after sorting alphabetically
	 * @param string $family
	 * @return string
	 */
	private function getFamilyName( string $family ): string {
		$family = explode( ',', $family );
		sort( $family );
		return $family[0];
	}

	/**
	 * Get all fonts that support the given language.
	 * @param string $lang
	 * @return string[][]
	 */
	public function getForLang( string $lang ): array {
		$out = [];
		foreach ( $this->getAll() as $font ) {
			if ( array_search( $lang, $font['langs'] ) !== false ) {
				$out[] = $font;
			}
		}
		return $out;
	}

	/**
	 * Turn a font family name into its canonical form (as its known by the OS).
	 * This will check lower- and snake-case variants.
	 * @param string|null $name
	 * @return string|bool The normalized name, or false if a matching font could not be found.
	 */
	public function resolveName( ?string $name ): string {
		if ( isset( $this->names[ $name ] ) ) {
			return $this->names[ $name ];
		}
		// Preserve backwards compatibility for an old font key.
		// https://github.com/wsexport/tool/blob/b4895ed5f986bb29dfc4b8663844c4145c4517bf/src/FontProvider.php#L25
		// The other legacy keys are all handled by the lowercase normalization below.
		if ( $name === 'linuxlibertine' ) {
			return 'Linux Libertine O';
		}
		foreach ( $this->getAll() as $fontName => $fontInfo ) {
			$names = [
				$fontName,
				strtolower( $fontName ),
				str_replace( ' ', '-', $fontName ),
				str_replace( ' ', '-', strtolower( $fontName ) ),
			];
			foreach ( $names as $n ) {
				if ( $n === $name ) {
					$this->names[ $name ] = $fontName;
					return $fontName;
				}
			}
		}
		return false;
	}

	/**
	 * Get CSS for the given font.
	 * @param string $name The font family name as defined in this class.
	 * @return string
	 */
	public function getCss( string $name ): string {
		$name = $this->resolveName( $name );
		if ( !$name ) {
			return '';
		}
		$font = $this->getOne( $name );
		$css = [];
		foreach ( $font['styles'] as $style => $styleInfo ) {
			// Information about the weight and slant values is in /usr/share/doc/fontconfig/fontconfig-user.html
			// and matching font-weight values at https://developer.mozilla.org/en-US/docs/Web/CSS/font-weight#Common_weight_name_mapping
			// Note that normal and bold are defined a bit differently.
			// Set font weight.
			$fontWeight = 'normal';
			$weight = $styleInfo['weight'];
			if ( $weight < 50 ) {
				$fontWeight = '100';
			} elseif ( $weight >= 50 && $weight < 55 ) {
				$fontWeight = '200';
			} elseif ( $weight >= 55 && $weight < 75 ) {
				$fontWeight = '300';
			} elseif ( $weight >= 75 && $weight <= 80 ) {
				$fontWeight = 'normal';
			} elseif ( $weight > 80 && $weight < 100 ) {
				$fontWeight = '500';
			} elseif ( $weight >= 100 && $weight < 180 ) {
				$fontWeight = '600';
			} elseif ( $weight >= 180 && $weight < 200 ) {
				$fontWeight = 'bold';
			} elseif ( $weight >= 200 && $weight < 205 ) {
				$fontWeight = '800';
			} elseif ( $weight >= 205 ) {
				$fontWeight = '900';
			}
			// Set font style.
			$fontStyle = 'normal';
			if ( $styleInfo['slant'] === '100' ) {
				$fontStyle = 'italic';
			} elseif ( $styleInfo['slant'] === '110' ) {
				$fontStyle = 'oblique';
			}
			$key = "name-$name-weight-$fontWeight-style-$fontStyle";
			if ( isset( $css[ $key ] ) ) {
				// Some font styles have multiples, so we only include the first one.
				continue;
			}
			$css[ $key ] = "@font-face {"
				. '  font-family: "' . $name . '";'
				. '  font-weight: ' . $fontWeight . ';'
				. '  font-style: ' . $fontStyle . ';'
				. '  src: url("fonts/' . basename( $styleInfo['file'] ) . '");'
				. '}';
		}
		$css[] = 'body { font-family: "' . $name . '" }';
		return implode( "\n", $css ) . "\n";
	}
}
