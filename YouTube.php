<?php
/**
 * Parser hook-based extension to show audio and video players
 * from YouTube, Archive.org, and NicoNico.
 *
 * @file
 * @ingroup Extensions
 * @author Przemek Piotrowski <ppiotr@wikia-inc.com> for Wikia, Inc.
 * @copyright © 2006-2008, Wikia Inc.
 * @license GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301
 * USA
 *
 * @todo one class (family) to rule 'em all
 * @todo make width/height_max != width/height_default; aoaudio height may be large - long playlist
 * @todo smart <video> and <audio> tag
 */

//======================================================================
// YOUTUBE EMBED
//======================================================================

class YouTube {

	/**
	 * Register all the new tags with the Parser.
	 *
	 * @param Parser &$parser
	 */
	public static function registerTags( &$parser ) {
		$parser->setHook( 'youtube', [ __CLASS__, 'embedYouTube' ] );
		$parser->setHook( 'aovideo', [ __CLASS__, 'embedArchiveOrgVideo' ] );
		$parser->setHook( 'aoaudio', [ __CLASS__, 'embedArchiveOrgAudio' ] );
		$parser->setHook( 'vimeo', [ __CLASS__, 'embedVideo' ] );
		$parser->setHook( 'dailymotion', [ __CLASS__, 'embedDailymotion' ] );
		$parser->setHook( 'nicovideo', [ __CLASS__, 'embedNicoVideo' ] );
	}

	/**
	 * Get the YouTube video ID from the supplied URL.
	 *
	 * @param string $url YouTube video URL
	 * @return string|bool Video ID on success, boolean false on failure
	 */
	public static function url2ytid( $url ) {
		// @see http://linuxpanda.wordpress.com/2013/07/24/ultimate-best-regex-pattern-to-get-grab-parse-youtube-video-id-from-any-youtube-link-url/
		$pattern = '~(?:http|https|)(?::\/\/|)(?:www\.|)(?:youtu\.be\/|youtube\.com(?:\/embed\/|\/v\/|\/watch\?v=|\/ytscreeningroom\?v=|\/feeds\/api\/videos\/|\/user\S*[^\w\-\s]|\S*[^\w\-\s]))([\w\-]{11})[a-z0-9;:@?&%=+\/\$_.-]*~i';
		$id = false;

		if ( preg_match( $pattern, $url, $preg ) ) {
			$id = $preg[1];
		} elseif ( preg_match( '/([0-9A-Za-z_-]+)/', $url, $preg ) ) {
			$id = $preg[1];
		}

		return $id;
	}

	/**
	 * Embeds a YouTube video.
	 *
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedYouTube( $input, $argv, $parser ) {
		global $wgYouTubeEnableLazyLoad;

		// Loads necessary modules for lazy loading:
		// Video poster image will be loaded first and replaced by the actual video once clicked.
		if ( $wgYouTubeEnableLazyLoad ) {
			$parser->getOutput()->addModules( [ 'ext.youtube.lazyload' ] );
		}

		$ytid   = '';
		$maxWidth = 960;
		$maxHeight = 720;
		$width = self::parseDimensionArg( $argv['width'], 560, $maxWidth );
		$height = self::parseDimensionArg( $argv['height'], 315, $maxWidth );

		// Sanitize input.
		if ( !empty( $argv['ytid'] ) ) {
			$ytid = self::url2ytid( $argv['ytid'] );
		} elseif ( !empty( $input ) ) {
			$ytid = self::url2ytid( $input );
		}

		// If URL cannot be parsed at all, return an empty string.
		if ( $ytid === false ) {
			return '';
		}
		
		// Define urlArgs container for every URL argument.
		$urlArgs = [];

		// If a starting timestamp is provided, include it in URL.
		if (
			!empty( $argv['start'] ) &&
			filter_var( $argv['start'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] )
		) {
			$urlArgs['start'] = $argv['start'];
		}

		// Adds ?autoplay=1 to the URL if the param is set.
		if ( !empty( $argv['autoplay'] ) || $wgYouTubeEnableLazyLoad ) {
			$urlArgs['autoplay'] = '1';
		}

		// Go through all the potential URL arguments and get them into one string.
		$argsStr = '';
		if ( !empty( $urlArgs ) ) {
			$argsStr = wfArrayToCgi( $urlArgs );
		}

		// Support YouTube's "enhanced privacy mode", in which "YouTube won't
		// store information about visitors on your web page unless they play
		// the video" if the privacy argument was supplied.
		// @see https://support.google.com/youtube/answer/171780?expand=PrivacyEnhancedMode#privacy
		$urlBase = '//www.youtube-nocookie.com/embed/';

		if ( !empty( $ytid ) ) {
			$url = $urlBase . $ytid . '?' . $argsStr;
			$content = $iframe = "<iframe data-extension=\"youtube\" width=\"{$width}\" height=\"{$height}\" src=\"{$url}\" frameborder=\"0\" allowfullscreen></iframe>";
			if ( $wgYouTubeEnableLazyLoad ) {
				$img =
					'<img width="' . $width . '" height="' . $height . '" src="'
					. '//img.youtube.com/vi/' . $ytid . '/default.jpg" />';
				$content =
					'<div style="width: ' . $width . 'px; height:' . $height . 'px;"'
					. 'class="ext-YouTube-video ext-YouTube-video--lazy" data-ytid="' . $ytid . '">'
					. $img
					. '<!-- ' . $iframe . ' -->'
					. '</div>';
			}
			return $content;
		}
	}


	//======================================================================
	// ARCHIVE.ORG EMBED
	//======================================================================
	
	/**
	 * Get an archive.org video/audio item ID from a URL.
	 *
	 * @param string $url Archive.org video/audio URL
	 * @return string|bool Video/audio ID on success, boolean false on failure
	 */
	public static function url2aoid( $url ) {
		$id = $url;

		if ( preg_match( '/(?:http|https|)(?::\/\/|)(?:www\.|)archive\.org\/details\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	/**
	 * Embeds archive.org audio and video.
	 * This technically also works for text/image/software embeds, however
	 * those do not fall under the scope of this extension.
	 *
	 * @param string $input
	 * @param array $argv
	 * @param bool $isAudio 
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedArchiveOrg( $input, $argv, $isAudio, $parser ) {
    
		$aoid   = '';
        
		$width = $maxWidth = 960;
		$height = $maxHeight = 720;
		$width = self::parseDimensionArg( $argv['width'], 560, $maxWidth );
        
		// Height of audio embed must always be 30px.
		$height = $isAudio ? 30 : self::parseDimensionArg( $argv['height'], 315, $maxHeight );
		
		// Sanitize input.
		if ( !empty( $argv['aoid'] ) ) {
			$aoid = self::url2aoid( $argv['aoid'] );
		} elseif ( !empty( $input ) ) {
			$aoid = self::url2aoid( $input );
		}

		// If URL cannot be parsed at all, return an empty string.
		if ( $aoid === false ) {
			return '';
		}

		// Return iframe embed object.
		if ( !empty( $aoid ) ) {
			return "<iframe src=\"https://archive.org/embed/$aoid\" width=\"$width\" height=\"$height\" frameborder=\"0\" webkitallowfullscreen=\"true\" mozallowfullscreen=\"true\" allowfullscreen></iframe>";
		}
	}

	
	public static function embedArchiveOrgVideo( $input, $argv, $parser ) {
		return self::embedArchiveOrg( $input, $argv, false, $parser );
	}

	public static function embedArchiveOrgAudio( $input, $argv, $parser ) {
		return self::embedArchiveOrg( $input, $argv, true, $parser );
	}

	//======================================================================
	// VIMEO EMBED
	//======================================================================

	/**
	 * Get a Vimeo video ID from a URL.
	 *
	 * @param string $url Vime video URL
	 * @return string|bool Video ID on success, boolean false on failure
	 */
	public static function url2vimeoid( $url ) {
		$id = $url;

		if ( preg_match( '/(?:http|https|)(?::\/\/|)(?:www\.|)vimeo\.com\/details\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	/**
	 * Embeds Vimeo videos.
	 *
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedVimeo( $input, $argv, $parser ) {
    
		$vimeoid   = '';
        
		$width = $maxWidth = 960;
		$height = $maxHeight = 720;
		$width = self::parseDimensionArg( $argv['width'], 640, $maxWidth );
		$height = self::parseDimensionArg( $argv['height'], 360, $maxHeight );
		
		// Sanitize input.
		if ( !empty( $argv['vimeoid'] ) ) {
			$vimeoid = self::url2vimeoid( $argv['vimeoid'] );
		} elseif ( !empty( $input ) ) {
			$vimeoid = self::url2vimeoid( $input );
		}

		// If URL cannot be parsed at all, return an empty string.
		if ( $vimeoid === false ) {
			return '';
		}

		// Return iframe embed object.
		if ( !empty( $vimeoid ) ) {
			return "<iframe title=\"vimeo-player\" src=\"https://player.vimeo.com/video/$vimeoid\" width=\"$width\" height=\"$height\" frameborder=\"0\" referrerpolicy=\"strict-origin-when-cross-origin\" allow=\"autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share\" allowfullscreen></iframe>";
		}
	}
	
	//======================================================================
	// DAILYMOTION EMBED
	//======================================================================

	/**
	 * Get a Dailymotion video ID from a URL.
	 *
	 * @param string $url Vime video URL
	 * @return string|bool Video ID on success, boolean false on failure
	 */
	public static function url2dmid( $url ) {
		$id = $url;

		if ( preg_match( '/(?:http|https|)(?::\/\/|)(?:www\.|)dailymotion\.com\/video\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	/**
	 * Embeds Dailymotion videos.
	 *
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedDailymotion( $input, $argv, $parser ) {
    
		$dmid   = '';
        
		$width = $maxWidth = 960;
		$height = $maxHeight = 720;
		$width = self::parseDimensionArg( $argv['width'], 640, $maxWidth );
		$height = self::parseDimensionArg( $argv['height'], 360, $maxHeight );
		
		// Sanitize input.
		if ( !empty( $argv['dmid'] ) ) {
			$dmid = self::url2dmid( $argv['dmid'] );
		} elseif ( !empty( $input ) ) {
			$dmid = self::url2dmid( $input );
		}

		// If URL cannot be parsed at all, return an empty string.
		if ( $dmid === false ) {
			return '';
		}

		// Return iframe embed object.
		if ( !empty( $dmid ) ) {
			return "<iframe src=\"https://geo.dailymotion.com/player.html?video=$dmid\" width=\"$width\" height=\"$height\" frameborder=\"0\" webkitallowfullscreen=\"true\" mozallowfullscreen=\"true\" allowfullscreen></iframe>";
	}
	
	//======================================================================
	// NICONICO EMBED
	//======================================================================

	/**
	 * Get a NicoNico video item ID from a URL.
	 *
	 * @param string $url NicoNico video URL
	 * @return string|bool Video ID on success, boolean false on failure
	 */
	public static function url2nvid( $url ) {
		$id = $url;

		if ( preg_match( '/(?:http|https|)(?::\/\/|)(?:www\.|)nicovideo\.jp\/watch\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	
	/**
	 * Embeds NicoNico videos.
	 *
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedNicoVideo( $input, $argv ) {
		$nvid = '';
		$maxWidth = 960;
		$maxHeight = 720;
		$width = self::parseDimensionArg( $argv['width'], 320, $maxWidth );
		$height = self::parseDimensionArg( $argv['height'], 180, $maxWidth );

		// Sanitize input.
		if ( !empty( $argv['nvid'] ) ) {
			$nvid = self::url2nvid( $argv['nvid'] );
		} elseif ( !empty( $input ) ) {
			$nvid = self::url2nvid( $input );
		}

		// If URL cannot be parsed at all, return an empty string.
		if ( $nvid === false ) {
			return '';
		}

		// Return Javascript embed object.
		if ( !empty( $nvid ) ) {
			return "<script type=\"application/javascript\" src=\"https://embed.nicovideo.jp/watch/$nvid/script?w=$width&h=$height\"></script>";
		}
	}

	//======================================================================
	// DIMENSION HANDLER
	//======================================================================

	/**
	 * Parse an argument representing a dimension and return a value appropriate
	 * for usage in markup. The argument must be in absolute pixels and may
	 * include a trailing 'px'.
	 *
	 * The passed default will be returned if the argument is not parseable, and
	 * the constraining range value will be returned if the argument is outside
	 * the range.
	 *
	 * If not specified, max will default to $default and min will default to 0.
	 * 
	 *
	 * @param string $value The argument value to parse
	 * @param int $default The value to return if $value cannot be parsed
	 * @param int|null $max The maximum range value; will default to $default
	 * @param int $min The minimum range value; defaults to 0
	 * @return int The parsed value as an integer
	 */
	private static function parseDimensionArg( $value, $default, $max = null, $min = 0 ) {
		
		if ( empty( $value ) ) {
			return $default;
		}

		if ( $max === null ) {
			$max = $default;
		}

		// Strip pixel unit from value so it can be treated as an integer.
		$value = str_ireplace( 'px', '', $value );

		// If dimension cannot be parsed, the default value will be returned.
		$value = filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'default' => $default ] ] );

		// If a dimension can be parsed but is out-of-range, the nearest valid
		// dimension will be returned.
		if ( $value < $min ) {
			$value = $min;
		} elseif ( $value > $max ) {
			$value = $max;
		}

		return $value;
	}
}
