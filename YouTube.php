<?php
/**
 * Parser hook-based extension to show audio and video players
 * from YouTube and other similar sites.
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
		$pattern = '~(?:http|https|)(?::\/\/|)(?:www.|)(?:youtu\.be\/|youtube\.com(?:\/embed\/|\/v\/|\/watch\?v=|\/ytscreeningroom\?v=|\/feeds\/api\/videos\/|\/user\S*[^\w\-\s]|\S*[^\w\-\s]))([\w\-]{11})[a-z0-9;:@?&%=+\/\$_.-]*~i';
		$id = false;

		if ( preg_match( $pattern, $url, $preg ) ) {
			$id = $preg[1];
		} elseif ( preg_match( '/([0-9A-Za-z_-]+)/', $url, $preg ) ) {
			$id = $preg[1];
		}

		return $id;
	}

	/**
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 *
	 * @return string
	 */
	public static function embedYouTube( $input, $argv, $parser ) {
		global $wgYouTubeEnableLazyLoad;

		// Loads necessary modules for lazy loading:
		// Video poster image will be loaded first and replaced by the actual video once clicked
		if ( $wgYouTubeEnableLazyLoad ) {
			$parser->getOutput()->addModules( [ 'ext.youtube.lazyload' ] );
		}

		$ytid   = '';
		$width = 560;
		$height = 315;
		$maxWidth = 960;
		$maxHeight = 720;

		if ( !empty( $argv['ytid'] ) ) {
			$ytid = self::url2ytid( $argv['ytid'] );
		} elseif ( !empty( $input ) ) {
			$ytid = self::url2ytid( $input );
		}

		// Did we not get an ID at all? That can happen if someone enters outright
		// gibberish and/or something that's not a YouTube URL.
		// Let's not even bother with generating useless HTML.
		if ( $ytid === false ) {
			return '';
		}

		// Support the pixel unit (px) in height/width parameters
		// This way these parameters won't fail the filter_var() tests below if the
		// user-supplied values were like 450px or 200px or something instead of
		// 450 or 200
		if ( !empty( $argv['height'] ) ) {
			$argv['height'] = str_replace( 'px', '', $argv['height'] );
		}

		if ( !empty( $argv['width'] ) ) {
			$argv['width'] = str_replace( 'px', '', $argv['width'] );
		}

		// Define urlArgs - container for every URL argument.
		$urlArgs = [];

		// Got a timestamp to start on? If yes, include it in URL.
		if (
			!empty( $argv['start'] ) &&
			filter_var( $argv['start'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] )
		) {
			$urlArgs['start'] = $argv['start'];
		}

		// Adds ?autoplay=1 to the URL is the param is set
		if ( !empty( $argv['autoplay'] ) || $wgYouTubeEnableLazyLoad ) {
			$urlArgs['autoplay'] = '1';
		}

		// Go through all the potential URL arguments and get them into one string.
		$argsStr = '';
		if ( !empty( $urlArgs ) ) {
			$argsStr = wfArrayToCgi( $urlArgs );
		}

		if (
			!empty( $argv['width'] ) &&
			filter_var( $argv['width'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['width'] <= $maxWidth
		) {
			$width = $argv['width'];
		}
		if (
			!empty( $argv['height'] ) &&
			filter_var( $argv['height'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['height'] <= $maxHeight
		) {
			$height = $argv['height'];
		}

		// Support YouTube's "enhanced privacy mode", in which "YouTube won’t
		// store information about visitors on your web page unless they play
		// the video" if the privacy argument was supplied
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
	 * Get an archive.org video/audio item ID from a URL
	 *
	 * @param string $url Archive.org video/audio URL
	 * @return string|bool Video/audio ID on success, boolean false on failure
	 */
	public static function url2aoid( $url ) {
		$id = $url;

		if ( preg_match( '/https:\/\/www\.archive\.org\/details\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	/**
	 * Embeds archive.org audio and video.
	 * This technically also works for text/image/software embeds, however
	 * those do not fall under the scope of this extension
	 *
	 * @param string $input
	 * @param array $argv
	 * @param bool $isAudio 
	 *
	 * @return string
	 */
	public static function embedArchiveOrg( $input, $argv, $isAudio ) {
		$aoid   = '';
		$width = 360
		$height = 315
		$maxWidth = 960;
		$maxHeight = 720;

		// If URL was entered, clean it to get ID
		if ( !empty( $argv['aoid'] ) ) {
			$aoid = self::url2aoid( $argv['aoid'] );
		} elseif ( !empty( $input ) ) {
			$aoid = self::url2aoid( $input );
		}

		// Did we not get an ID at all? That can happen if someone enters outright
		// gibberish and/or something that's not a YouTube URL.
		// Let's not even bother with generating useless HTML.
		if ( $aoid === false ) {
			return '';
		}

		// Support the pixel unit (px) in height/width parameters
		// This way these parameters won't fail the filter_var() tests below if the
		// user-supplied values were like 450px or 200px or something instead of
		// 450 or 200
		if ( !empty( $argv['height'] ) ) {
			$argv['height'] = str_replace( 'px', '', $argv['height'] );
		}
		if ( !empty( $argv['width'] ) ) {
			$argv['width'] = str_replace( 'px', '', $argv['width'] );
		}

		// Set width and height, if specified
		// For audio embeds, custom height is not supported
		if (
			!empty( $argv['width'] ) &&
			filter_var( $argv['width'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['width'] <= $maxWidth
		) {
			$width = $argv['width'];
		}
		if (
			!isAudio &&
			!empty( $argv['height'] ) &&
			filter_var( $argv['height'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['height'] <= $maxHeight
		) {
			$height = $argv['height'];
		}

		// Return iframe embed object
		if ( !empty( $aoid ) ) {
			return "<iframe src=\"https://archive.org/embed/$aoid\" width=\"$width\" height=\"$height\" frameborder=\"0\" webkitallowfullscreen=\"true\" mozallowfullscreen=\"true\" allowfullscreen></iframe>";
		}
	}

	
	public static function embedArchiveOrgVideo( $input, $argv ) {
		return embedArchiveOrg( $input, $argv, false );
	}

	public static function embedArchiveOrgAudio( $input, $argv ) {
		return embedArchiveOrg( $input, $argv, true );
	}

	//======================================================================
	// NICONICO EMBED
	//======================================================================

	/**
	 * Get a NicoNico video item ID from a URL
	 *
	 * @param string $url NicoNico video URL
	 * @return string|bool Video ID on success, boolean false on failure
	 */
	public static function url2aoid( $url ) {
		$id = $url;

		if ( preg_match( '/https:\/\/www\.nicovideo\.jp\/watch\/(.+)$/', $url, $preg ) ) {
			$id = $preg[1];
		}

		preg_match( '/([0-9A-Za-z_\/.-]+)/', $id, $preg );
		$id = $preg[1];

		return $id;
	}

	
	/**
	 * Embeds NicoNico videos
	 *
	 * @param string $input
	 * @param array $argv
	 *
	 * @return string
	 */
	public static function embedNicoVideo( $input, $argv ) {
		$nvid = '';
		$width = 320
		$height = 180
		$maxWidth = 960;
		$maxHeight = 720;

		// If URL was entered, clean it to get ID
		if ( !empty( $argv['nvid'] ) ) {
			$nvid = self::url2nvid( $argv['nvid'] );
		} elseif ( !empty( $input ) ) {
			$nvid = self::url2nvid( $input );
		}

		// Did we not get an ID at all? That can happen if someone enters outright
		// gibberish and/or something that's not a YouTube URL.
		// Let's not even bother with generating useless HTML.
		if ( $nvid === false ) {
			return '';
		}

		// Support the pixel unit (px) in height/width parameters
		// This way these parameters won't fail the filter_var() tests below if the
		// user-supplied values were like 450px or 200px or something instead of
		// 450 or 200
		if ( !empty( $argv['height'] ) ) {
			$argv['height'] = str_replace( 'px', '', $argv['height'] );
		}
		if ( !empty( $argv['width'] ) ) {
			$argv['width'] = str_replace( 'px', '', $argv['width'] );
		}

		// Set width and height, if specified
		// For audio embeds, custom height is not supported
		if (
			!empty( $argv['width'] ) &&
			filter_var( $argv['width'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['width'] <= $maxWidth
		) {
			$width = $argv['width'];
		}
		if (
			!empty( $argv['height'] ) &&
			filter_var( $argv['height'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ) &&
			$argv['height'] <= $maxHeight
		) {
			$height = $argv['height'];
		}

		if ( !empty( $nvid ) ) {
			return "<script type=\"application/javascript\" src=\"https://embed.nicovideo.jp/watch/$nvid/script?w=$width&h=$height\"></script>"
		}
	}

}
