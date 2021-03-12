<?php

namespace App\Generator;

use App\Book;
use App\Cleaner\BookCleanerEpub;
use App\FontProvider;
use App\Util\Api;
use App\Util\Util;
use Exception;
use IntlDateFormatter;
use Krinkle\Intuition\Intuition;
use ZipArchive;

/**
 * @author Thomas Pellissier Tanon
 * @copyright 2011 Thomas Pellissier Tanon
 * @license GPL-2.0-or-later
 */

/**
 * create an epub file
 */
class EpubGenerator implements FormatGenerator {

	/**
	 * array key/value that contain translated strings
	 */
	protected $i18n = [];

	/** @var FontProvider */
	protected $fontProvider;

	/** @var Api */
	protected $api;

	/** @var Intuition */
	private $intuition;

	public function __construct( FontProvider $fontProvider, Api $api, Intuition $intuition ) {
		$this->fontProvider = $fontProvider;
		$this->api = $api;
		$this->intuition = $intuition;
	}

	/**
	 * return the extension of the generated file
	 * @return string
	 */
	public function getExtension() {
		return 'epub';
	}

	/**
	 * return the mimetype of the generated file
	 * @return string
	 */
	public function getMimeType() {
		return 'application/epub+zip';
	}

	/**
	 * create the file
	 * @param Book $book the content of the book
	 * @return string
	 */
	public function create( Book $book ) {
		$oldBookTitle = $book->title;
		$css = $this->getCss( $book );
		$this->i18n = Util::getI18n( $this->api, $book->lang );
		$wsUrl = Util::wikisourceUrl( $book->lang, $book->title );
		$cleaner = new BookCleanerEpub();
		$cleaner->clean( $book, Util::wikisourceUrl( $book->lang ) );
		$fileName = Util::buildTemporaryFileName( $book->title, 'epub' );
		$zip = $this->createZipFile( $fileName );
		$zip->addFromString( 'META-INF/container.xml', $this->getXmlContainer() );
		$zip->addFromString( 'META-INF/com.apple.ibooks.display-options.xml', $this->getAppleIBooksDisplayOptionsXml() );
		$zip->addFromString( 'OPS/content.opf', $this->getOpfContent( $book, $wsUrl ) );
		$zip->addFromString( 'OPS/toc.ncx', $this->getNcxToc( $book, $wsUrl ) );
		$zip->addFromString( 'OPS/nav.xhtml', $this->getXhtmlNav( $book ) );
		$zip->addFromString( 'OPS/title.xhtml', $this->getXhtmlTitle( $book ) );
		$zip->addFromString( 'OPS/about.xhtml', $this->getXhtmlAbout( $book, $wsUrl ) );
		$dir = dirname( __DIR__, 2 ) . '/resources';
		if ( $book->options['images'] ) {
			$zip->addFile( $dir . '/images/Accueil_scribe.png', 'OPS/images/Accueil_scribe.png' );
		}

		$font = $this->fontProvider->getOne( $book->options['fonts'] );
		if ( $font !== null ) {
			foreach ( $font['styles'] as $styleInfo ) {
				$zip->addFile( $styleInfo['file'], 'OPS/fonts/' . basename( $styleInfo['file'] ) );
			}
		}

		if ( $book->content ) {
			$zip->addFromString( 'OPS/' . $book->title . '.xhtml', $book->content->saveXML() );
		}
		if ( !empty( $book->chapters ) ) {
			foreach ( $book->chapters as $chapter ) {
				$zip->addFromString( 'OPS/' . $chapter->title . '.xhtml', $chapter->content->saveXML() );
				foreach ( $chapter->chapters as $subpage ) {
					$zip->addFromString( 'OPS/' . $subpage->title . '.xhtml', $subpage->content->saveXML() );
				}
			}
		}
		foreach ( $book->pictures as $picture ) {
			$picture->saveToZip( $zip, 'OPS/images/' . $picture->title );
		}
		$zip->addFromString( 'OPS/main.css', $css );
		$book->title = $oldBookTitle;

		$zip->close();
		return $fileName;
	}

	/**
	 * Return the OPF description file
	 * @param $book Book
	 * @param $wsUrl string URL to the main page in Wikisource
	 */
	private function getOpfContent( Book $book, $wsUrl ) {
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
		      <package xmlns="http://www.idpf.org/2007/opf" unique-identifier="uid" version="3.0" xml:lang="' . $book->lang . '" prefix="cc: http://creativecommons.org/ns#">
			     <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
				    <dc:identifier id="uid">' . $wsUrl . '</dc:identifier>
				    <meta property="dcterms:modified">' . date( 'Y-m-d\TH:i:s' ) . 'Z</meta>
				    <dc:language>' . $book->lang . '</dc:language>
				    <dc:title id="meta-title">' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</dc:title>
				    <meta refines="#meta-title" property="title-type">main</meta>
				    <dc:source>' . $wsUrl . '</dc:source>
				    <dc:rights xml:lang="en">Creative Commons BY-SA 3.0</dc:rights>
				    <link rel="cc:license" href="http://creativecommons.org/licenses/by-sa/3.0/" />
				    <dc:rights xml:lang="en">GNU Free Documentation License</dc:rights>
				    <link rel="cc:license" href="http://www.gnu.org/copyleft/fdl.html" />
				    <dc:contributor id="meta-bkp">Wikisource</dc:contributor>
				    <meta refines="#meta-bkp" property="role" scheme="marc:relators">bkp</meta>';
		if ( $book->author != '' ) {
			$content .= '<dc:creator id="meta-aut">' . htmlspecialchars( $book->author, ENT_QUOTES ) . '</dc:creator>
					       <meta refines="#meta-aut" property="role" scheme="marc:relators">aut</meta>';
		}
		if ( $book->translator != '' ) {
			$content .= '<dc:contributor id="meta-trl">' . htmlspecialchars( $book->translator, ENT_QUOTES ) . '</dc:contributor>
					       <meta refines="#meta-trl" property="role" scheme="marc:relators">trl</meta>';
		}
		if ( $book->illustrator != '' ) {
			$content .= '<dc:contributor id="meta-ill">' . htmlspecialchars( $book->illustrator, ENT_QUOTES ) . '</dc:contributor>
					       <meta refines="#meta-ill" property="role" scheme="marc:relators">ill</meta>';
		}
		if ( $book->publisher != '' ) {
			$content .= '<dc:publisher>' . htmlspecialchars( $book->publisher, ENT_QUOTES ) . '</dc:publisher>';
		}
		if ( $book->year != '' ) {
			$content .= '<dc:date>' . htmlspecialchars( $book->year, ENT_QUOTES ) . '</dc:date>';
		}
		$cover = $book->options['images'] ? $this->getCover( $book ) : null;
		$content .= '<meta name="cover" content="' . ( $cover ? $cover->title : 'title' ) . '" />';
		$content .= '</metadata>
			     <manifest>
				    <item href="nav.xhtml" id="nav" media-type="application/xhtml+xml" properties="nav" />
				    <item href="toc.ncx" id="ncx" media-type="application/x-dtbncx+xml"/>'; // deprecated
		$content .= '<item id="title" href="title.xhtml" media-type="application/xhtml+xml" />
				    <item id="mainCss" href="main.css" media-type="text/css" />';
		if ( $book->options['images'] ) {
			$content .= '<item id="Accueil_scribe.png" href="images/Accueil_scribe.png" media-type="image/png" />';
		}
		$font = $this->fontProvider->getOne( $book->options['fonts'] );
		if ( $font !== null ) {
			foreach ( $font['styles'] as $style => $styleInfo ) {
				$path = $styleInfo['file'];
				// Font mime types are listed at https://www.w3.org/publishing/epub32/epub-spec.html#cmt-grp-font
				// They conveniently align with file extensions.
				$mime = pathinfo( $path, PATHINFO_EXTENSION );
				$content .= '<item id="fonts-' . basename( $path ) . '"'
					. ' href="fonts/' . basename( $path ) . '"'
					. ' media-type="font/' . $mime . '" />' . "\n";
			}
		}
		if ( $book->content ) {
			$content .= '<item id="' . $book->title . '" href="' . $book->title . '.xhtml" media-type="application/xhtml+xml" />' . "\n";
		}
		foreach ( $book->chapters as $chapter ) {
			$content .= '<item id="' . $chapter->title . '" href="' . $chapter->title . '.xhtml" media-type="application/xhtml+xml" />' . "\n";
			foreach ( $chapter->chapters as $subpage ) {
				$content .= '<item id="' . $subpage->title . '" href="' . $subpage->title . '.xhtml" media-type="application/xhtml+xml" />' . "\n";
			}
		}
		foreach ( $book->pictures as $pictureId => $picture ) {
			$content .= '<item id="' . $picture->title . '" href="images/' . $picture->title . '" media-type="' . $picture->mimetype . '"';
			if ( $book->cover === $pictureId ) {
				$content .= ' properties="cover-image"';
			}
			$content .= ' />' . "\n";
		}
		$content .= '<item id="about" href="about.xhtml" media-type="application/xhtml+xml" />
			     </manifest>
			     <spine toc="ncx">';
		$content .= '<itemref idref="title" linear="yes" />';
		if ( $book->content ) {
			$content .= '<itemref idref="' . $book->title . '" linear="yes" />';
		}
		if ( !empty( $book->chapters ) ) {
			foreach ( $book->chapters as $chapter ) {
				$content .= '<itemref idref="' . $chapter->title . '" linear="yes" />';
				foreach ( $chapter->chapters as $subpage ) {
					$content .= '<itemref idref="' . $subpage->title . '" linear="yes" />';
				}
			}
		}
		$content .= '<itemref idref="about" linear="yes" />
			     </spine>
		      </package>';

		return $content;
	}

	private function getXmlContainer() {
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
			<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
				<rootfiles>
					<rootfile full-path="OPS/content.opf" media-type="application/oebps-package+xml" />
				</rootfiles>
			</container>';

		return $content;
	}

	private function getAppleIBooksDisplayOptionsXml() {
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
			<display_options>
				<platform name="*">
					<option name="specified-fonts">true</option>
				</platform>
			</display_options>';

		return $content;
	}

	private function getNcxToc( Book $book, $wsUrl ) {
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
			<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
				<head>
					<meta name="dtb:uid" content="' . $wsUrl . '" />
					<meta name="dtb:depth" content="1" />
					<meta name="dtb:totalPageCount" content="0" />
					<meta name="dtb:maxPageNumber" content="0" />
				</head>
				<docTitle><text>' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</text></docTitle>
				<docAuthor><text>' . htmlspecialchars( $book->author, ENT_QUOTES ) . '</text></docAuthor>
				<navMap>
					<navPoint id="title" playOrder="1">
						<navLabel><text>' . $this->i18n['title_page'] . '</text></navLabel>
						<content src="title.xhtml"/>
					</navPoint>';
		$order = 2;
		if ( $book->content ) {
			$content .= '<navPoint id="' . $book->title . '" playOrder="' . $order . '">
						    <navLabel><text>' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</text></navLabel>
						    <content src="' . $book->title . '.xhtml" />
					    </navPoint>';
			$order++;
		}
		if ( !empty( $book->chapters ) ) {
			foreach ( $book->chapters as $chapter ) {
				if ( $chapter->name != '' ) {
					$content .= '<navPoint id="' . $chapter->title . '" playOrder="' . $order . '">
									    <navLabel><text>' . htmlspecialchars( $chapter->name, ENT_QUOTES ) . '</text></navLabel>
									    <content src="' . $chapter->title . '.xhtml" />';
					$order++;
					foreach ( $chapter->chapters as $subpage ) {
						if ( $subpage->name != '' ) {
							$content .= '<navPoint id="' . $subpage->title . '" playOrder="' . $order . '">
											    <navLabel><text>' . htmlspecialchars( $subpage->name, ENT_QUOTES ) . '</text></navLabel>
											    <content src="' . $subpage->title . '.xhtml" />
										</navPoint>';
							$order++;
						}
					}
					$content .= '</navPoint>';
				}
			}
		}
		$content .= '<navPoint id="about" playOrder="' . $order . '">
						<navLabel>
							<text>' . htmlspecialchars( $this->i18n['about'], ENT_QUOTES ) . '</text>
						</navLabel>
						<content src="about.xhtml"/>
					</navPoint>
			       </navMap>
			</ncx>';

		return $content;
	}

	private function getXhtmlNav( Book $book ) {
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
		      <!DOCTYPE html>
		      <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . $book->lang . '">
			     <head>
				    <title>' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</title>
				    <meta charset="utf-8" />
				    <link type="text/css" rel="stylesheet" href="main.css" />
			     </head>
			     <body>
				    <section epub:type="frontmatter toc">
					   <nav epub:type="toc" id="toc">
						  <ol>
							 <li id="toc-title">
								<a href="title.xhtml">' . htmlspecialchars( $this->i18n['title_page'], ENT_QUOTES ) . '</a>
							 </li>';
		if ( $book->content ) {
			$content .= '<li id="toc-' . $book->title . '">
							 <a href="' . $book->title . '.xhtml">' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</a>
						  </li>';
		}
		if ( !empty( $book->chapters ) ) {
			foreach ( $book->chapters as $chapter ) {
				if ( $chapter->name != '' ) {
					$content .= '<li id="toc-' . $chapter->title . '">
								<a href="' . $chapter->title . '.xhtml">' . htmlspecialchars( $chapter->name, ENT_QUOTES ) . '</a>';
					if ( !empty( $chapter->chapters ) ) {
						$content .= '<ol>';
						foreach ( $chapter->chapters as $subpage ) {
							if ( $subpage->name != '' ) {
								$content .= '<li id="toc-' . $subpage->title . '">
										      <a href="' . $subpage->title . '.xhtml">' . htmlspecialchars( $subpage->name, ENT_QUOTES ) . '</a>
									       </li>';
							}
						}
						$content .= '</ol>';
					}
					$content .= '</li>';
				}
			}
		}
		$content .= '<li id="toc-about">
								<a href="about.xhtml">' . htmlspecialchars( $this->i18n['about'], ENT_QUOTES ) . '</a>
							 </li>
						  </ol>
					   </nav>
					   <nav epub:type="landmarks" id="guide">
						  <ol>
							    <li>
								  <a epub:type="bodymatter" href="' . $book->title . '.xhtml">' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</a>
							    </li>
							    <li>
								  <a epub:type="copyright-page" href="about.xhtml">' . htmlspecialchars( $this->i18n['about'], ENT_QUOTES ) . '</a>
							    </li>
						  </ol>
					    </nav>
				      </section>
			        </body>
		      </html>';

		return $content;
	}

	private function getCover( Book $book ) {
		foreach ( $book->pictures as $pictureId => $picture ) {
			if ( $book->cover === $pictureId ) {
				return $picture;
			}
		}
		return null;
	}

	private function getXhtmlTitle( Book $book ) {
		$footerElements = [];
		if ( $book->publisher != '' ) {
			$footerElements[] = $book->publisher;
		}
		if ( $book->periodical != '' ) {
			$footerElements[] = $book->periodical;
		}
		if ( $book->place != '' ) {
			$footerElements[] = $book->place;
		}
		if ( $book->year != '' ) {
			$footerElements[] = $book->year;
		}

		$formatter = new IntlDateFormatter( $book->lang, IntlDateFormatter::LONG, IntlDateFormatter::NONE );
		$content = '<?xml version="1.0" encoding="UTF-8" ?>
			<!DOCTYPE html>
			<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . $book->lang . '" dir="' . Util::getLanguageDirection( $book->lang ) . '">
				<head>
					<title>' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</title>
					<link type="text/css" rel="stylesheet" href="main.css" />
				</head>
				<body style="background-color: ghostwhite; text-align: center; margin-right: auto; margin-left: auto; text-indent: 0;">
					<h2>' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</h2>
					<h3>' . htmlspecialchars( $book->author, ENT_QUOTES ) . '</h3>
					<br />';
		if ( $book->options['images'] ) {
			$content .= '<img alt="" src="images/Accueil_scribe.png" />';
		}
		$content .= '<br />
					<h5>' . implode( ', ', $footerElements ) . '</h5>
					<br />
					<h6>' . str_replace( '%d', $formatter->format( time() ), htmlspecialchars( $this->i18n['exported_from_wikisource_the'], ENT_QUOTES ) ) . '</h6>
				</body>
			</html>';
		return $content;
	}

	private function getXhtmlAbout( Book $book, $wsUrl ) {
		if ( empty( $book->credits ) ) {
			$list = $this->intuition->msg( 'credits-default-message' ) ??
					'credits-default-message missing';
			$listBot = '';
		} else {
			$list = '<ul>';
			$listBot = '<ul>';
			foreach ( $book->credits as $name => $value ) {
				if ( $value[ 'bot' ] !== null ) {
					$listBot .= '<li>' . htmlspecialchars( $name, ENT_QUOTES ) . "</li>\n";
				} else {
					$list .= '<li>' . htmlspecialchars( $name, ENT_QUOTES ) . "</li>\n";
				}
			}
			$list .= '</ul>';
			$listBot .= '</ul>';
		}

		$about = $this->api->getAboutPage();
		if ( $about == '' ) {
			$about = Util::getXhtmlFromContent( $book->lang, $list, $this->i18n['about'] );
		} else {
			$about = str_replace( '{CONTRIBUTORS}', $list, $about );
			$about = str_replace( '{BOT-CONTRIBUTORS}', $listBot, $about );
			$about = str_replace( '{URL}', '<a href="' . $wsUrl . '">' . htmlspecialchars( $book->name, ENT_QUOTES ) . '</a>', $about );
		}

		return $about;
	}

	private function getCss( Book $book ) {
		$css = $this->fontProvider->getCss( $book->options['fonts'] );
		$css .= Util::getTempFile( $this->api, $book->lang, 'epub.css' );

		return $css;
	}

	private function createZipFile( $fileName ) {
		// This is a simple ZIP file with only the uncompressed "mimetype" file with as value "application/epub+zip"
		file_put_contents( $fileName, base64_decode( "UEsDBBQAAAAAAPibYUhvYassFAAAABQAAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi9lcHViK3ppcFBLAQIAABQAAAAAAPibYUhvYassFAAAABQAAAAIAAAAAAAAAAAAIAAAAAAAAABtaW1ldHlwZVBLBQYAAAAAAQABADYAAAA6AAAAAAA=" ) );
		$zip = new ZipArchive();
		if ( $zip->open( $fileName, ZipArchive::CREATE ) !== true ) {
			throw new Exception( 'Unnable to open the ZIP file ' . $fileName );
		}
		return $zip;
	}
}
