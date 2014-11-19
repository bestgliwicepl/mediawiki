<?php
/**
 * Generic handler for bitmap images.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Media
 */

/**
 * Generic handler for bitmap images
 *
 * @ingroup Media
 */
class BitmapHandler extends TransformationalImageHandler {

	/**
	 * Returns which scaler type should be used. Creates parent directories
	 * for $dstPath and returns 'client' on error
	 *
	 * @param string $dstPath
	 * @param bool $checkDstPath
	 * @return string|Callable One of client, im, custom, gd, imext or an array( object, method )
	 */
	protected function getScalerType( $dstPath, $checkDstPath = true ) {
		global $wgUseImageResize, $wgUseImageMagick, $wgCustomConvertCommand;

		if ( !$dstPath && $checkDstPath ) {
			# No output path available, client side scaling only
			$scaler = 'client';
		} elseif ( !$wgUseImageResize ) {
			$scaler = 'client';
		} elseif ( $wgUseImageMagick ) {
			$scaler = 'im';
		} elseif ( $wgCustomConvertCommand ) {
			$scaler = 'custom';
		} elseif ( function_exists( 'imagecreatetruecolor' ) ) {
			$scaler = 'gd';
		} elseif ( class_exists( 'Imagick' ) ) {
			$scaler = 'imext';
		} else {
			$scaler = 'client';
		}

		return $scaler;
	}

	/**
	 * Transform an image using ImageMagick
	 *
	 * @param File $image File associated with this thumbnail
	 * @param array $params Array with scaler params
	 *
	 * @return MediaTransformError Error object if error occurred, false (=no error) otherwise
	 */
	protected function transformImageMagick( $image, $params ) {
		# use ImageMagick
		global $wgSharpenReductionThreshold, $wgSharpenParameter, $wgMaxAnimatedGifArea,
			$wgImageMagickTempDir, $wgImageMagickConvertCommand;

		$quality = array();
		$sharpen = array();
		$scene = false;
		$animation_pre = array();
		$animation_post = array();
		$decoderHint = array();
		if ( $params['mimeType'] == 'image/jpeg' ) {
			$qualityVal = isset( $params['quality'] ) ? (string)$params['quality'] : null;
			$quality = array( '-quality', $qualityVal ?: '80' ); // 80%
			# Sharpening, see bug 6193
			if ( ( $params['physicalWidth'] + $params['physicalHeight'] )
				/ ( $params['srcWidth'] + $params['srcHeight'] )
				< $wgSharpenReductionThreshold
			) {
				$sharpen = array( '-sharpen', $wgSharpenParameter );
			}
			if ( version_compare( $this->getMagickVersion(), "6.5.6" ) >= 0 ) {
				// JPEG decoder hint to reduce memory, available since IM 6.5.6-2
				$decoderHint = array( '-define', "jpeg:size={$params['physicalDimensions']}" );
			}
		} elseif ( $params['mimeType'] == 'image/png' ) {
			$quality = array( '-quality', '95' ); // zlib 9, adaptive filtering

		} elseif ( $params['mimeType'] == 'image/gif' ) {
			if ( $this->getImageArea( $image ) > $wgMaxAnimatedGifArea ) {
				// Extract initial frame only; we're so big it'll
				// be a total drag. :P
				$scene = 0;
			} elseif ( $this->isAnimatedImage( $image ) ) {
				// Coalesce is needed to scale animated GIFs properly (bug 1017).
				$animation_pre = array( '-coalesce' );
				// We optimize the output, but -optimize is broken,
				// use optimizeTransparency instead (bug 11822)
				if ( version_compare( $this->getMagickVersion(), "6.3.5" ) >= 0 ) {
					$animation_post = array( '-fuzz', '5%', '-layers', 'optimizeTransparency' );
				}
			}
		} elseif ( $params['mimeType'] == 'image/x-xcf' ) {
			// Before merging layers, we need to set the background
			// to be transparent to preserve alpha, as -layers merge
			// merges all layers on to a canvas filled with the
			// background colour. After merging we reset the background
			// to be white for the default background colour setting
			// in the PNG image (which is used in old IE)
			$animation_pre = array(
				'-background', 'transparent',
				'-layers', 'merge',
				'-background', 'white',
			);
			wfSuppressWarnings();
			$xcfMeta = unserialize( $image->getMetadata() );
			wfRestoreWarnings();
			if ( $xcfMeta
				&& isset( $xcfMeta['colorType'] )
				&& $xcfMeta['colorType'] === 'greyscale-alpha'
				&& version_compare( $this->getMagickVersion(), "6.8.9-3" ) < 0
			) {
				// bug 66323 - Greyscale images not rendered properly.
				// So only take the "red" channel.
				$channelOnly = array( '-channel', 'R', '-separate' );
				$animation_pre = array_merge( $animation_pre, $channelOnly );
			}
		}

		// Use one thread only, to avoid deadlock bugs on OOM
		$env = array( 'OMP_NUM_THREADS' => 1 );
		if ( strval( $wgImageMagickTempDir ) !== '' ) {
			$env['MAGICK_TMPDIR'] = $wgImageMagickTempDir;
		}

		$rotation = isset( $params['disableRotation'] ) ? 0 : $this->getRotation( $image );
		list( $width, $height ) = $this->extractPreRotationDimensions( $params, $rotation );

		$cmd = call_user_func_array( 'wfEscapeShellArg', array_merge(
			array( $wgImageMagickConvertCommand ),
			$quality,
			// Specify white background color, will be used for transparent images
			// in Internet Explorer/Windows instead of default black.
			array( '-background', 'white' ),
			$decoderHint,
			array( $this->escapeMagickInput( $params['srcPath'], $scene ) ),
			$animation_pre,
			// For the -thumbnail option a "!" is needed to force exact size,
			// or ImageMagick may decide your ratio is wrong and slice off
			// a pixel.
			array( '-thumbnail', "{$width}x{$height}!" ),
			// Add the source url as a comment to the thumb, but don't add the flag if there's no comment
			( $params['comment'] !== ''
				? array( '-set', 'comment', $this->escapeMagickProperty( $params['comment'] ) )
				: array() ),
			array( '-depth', 8 ),
			$sharpen,
			array( '-rotate', "-$rotation" ),
			$animation_post,
			array( $this->escapeMagickOutput( $params['dstPath'] ) ) ) );

		wfDebug( __METHOD__ . ": running ImageMagick: $cmd\n" );
		wfProfileIn( 'convert' );
		$retval = 0;
		$err = wfShellExecWithStderr( $cmd, $retval, $env );
		wfProfileOut( 'convert' );

		if ( $retval !== 0 ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );

			return $this->getMediaTransformError( $params, "$err\nError code: $retval" );
		}

		return false; # No error
	}

	/**
	 * Transform an image using the Imagick PHP extension
	 *
	 * @param File $image File associated with this thumbnail
	 * @param array $params Array with scaler params
	 *
	 * @return MediaTransformError Error object if error occurred, false (=no error) otherwise
	 */
	protected function transformImageMagickExt( $image, $params ) {
		global $wgSharpenReductionThreshold, $wgSharpenParameter, $wgMaxAnimatedGifArea;

		try {
			$im = new Imagick();
			$im->readImage( $params['srcPath'] );

			if ( $params['mimeType'] == 'image/jpeg' ) {
				// Sharpening, see bug 6193
				if ( ( $params['physicalWidth'] + $params['physicalHeight'] )
					/ ( $params['srcWidth'] + $params['srcHeight'] )
					< $wgSharpenReductionThreshold
				) {
					// Hack, since $wgSharpenParamater is written specifically for the command line convert
					list( $radius, $sigma ) = explode( 'x', $wgSharpenParameter );
					$im->sharpenImage( $radius, $sigma );
				}
				$qualityVal = isset( $params['quality'] ) ? (string)$params['quality'] : null;
				$im->setCompressionQuality( $qualityVal ?: 80 );
			} elseif ( $params['mimeType'] == 'image/png' ) {
				$im->setCompressionQuality( 95 );
			} elseif ( $params['mimeType'] == 'image/gif' ) {
				if ( $this->getImageArea( $image ) > $wgMaxAnimatedGifArea ) {
					// Extract initial frame only; we're so big it'll
					// be a total drag. :P
					$im->setImageScene( 0 );
				} elseif ( $this->isAnimatedImage( $image ) ) {
					// Coalesce is needed to scale animated GIFs properly (bug 1017).
					$im = $im->coalesceImages();
				}
			}

			$rotation = isset( $params['disableRotation'] ) ? 0 : $this->getRotation( $image );
			list( $width, $height ) = $this->extractPreRotationDimensions( $params, $rotation );

			$im->setImageBackgroundColor( new ImagickPixel( 'white' ) );

			// Call Imagick::thumbnailImage on each frame
			foreach ( $im as $i => $frame ) {
				if ( !$frame->thumbnailImage( $width, $height, /* fit */ false ) ) {
					return $this->getMediaTransformError( $params, "Error scaling frame $i" );
				}
			}
			$im->setImageDepth( 8 );

			if ( $rotation ) {
				if ( !$im->rotateImage( new ImagickPixel( 'white' ), 360 - $rotation ) ) {
					return $this->getMediaTransformError( $params, "Error rotating $rotation degrees" );
				}
			}

			if ( $this->isAnimatedImage( $image ) ) {
				wfDebug( __METHOD__ . ": Writing animated thumbnail\n" );
				// This is broken somehow... can't find out how to fix it
				$result = $im->writeImages( $params['dstPath'], true );
			} else {
				$result = $im->writeImage( $params['dstPath'] );
			}
			if ( !$result ) {
				return $this->getMediaTransformError( $params,
					"Unable to write thumbnail to {$params['dstPath']}" );
			}
		} catch ( ImagickException $e ) {
			return $this->getMediaTransformError( $params, $e->getMessage() );
		}

		return false;
	}

	/**
	 * Transform an image using a custom command
	 *
	 * @param File $image File associated with this thumbnail
	 * @param array $params Array with scaler params
	 *
	 * @return MediaTransformError Error object if error occurred, false (=no error) otherwise
	 */
	protected function transformCustom( $image, $params ) {
		# Use a custom convert command
		global $wgCustomConvertCommand;

		# Variables: %s %d %w %h
		$src = wfEscapeShellArg( $params['srcPath'] );
		$dst = wfEscapeShellArg( $params['dstPath'] );
		$cmd = $wgCustomConvertCommand;
		$cmd = str_replace( '%s', $src, str_replace( '%d', $dst, $cmd ) ); # Filenames
		$cmd = str_replace( '%h', wfEscapeShellArg( $params['physicalHeight'] ),
			str_replace( '%w', wfEscapeShellArg( $params['physicalWidth'] ), $cmd ) ); # Size
		wfDebug( __METHOD__ . ": Running custom convert command $cmd\n" );
		wfProfileIn( 'convert' );
		$retval = 0;
		$err = wfShellExecWithStderr( $cmd, $retval );
		wfProfileOut( 'convert' );

		if ( $retval !== 0 ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );

			return $this->getMediaTransformError( $params, $err );
		}

		return false; # No error
	}

	/**
	 * Transform an image using the built in GD library
	 *
	 * @param File $image File associated with this thumbnail
	 * @param array $params Array with scaler params
	 *
	 * @return MediaTransformError Error object if error occurred, false (=no error) otherwise
	 */
	protected function transformGd( $image, $params ) {
		# Use PHP's builtin GD library functions.
		#
		# First find out what kind of file this is, and select the correct
		# input routine for this.

		$typemap = array(
			'image/gif' => array( 'imagecreatefromgif', 'palette', false, 'imagegif' ),
			'image/jpeg' => array( 'imagecreatefromjpeg', 'truecolor', true,
				array( __CLASS__, 'imageJpegWrapper' ) ),
			'image/png' => array( 'imagecreatefrompng', 'bits', false, 'imagepng' ),
			'image/vnd.wap.wbmp' => array( 'imagecreatefromwbmp', 'palette', false, 'imagewbmp' ),
			'image/xbm' => array( 'imagecreatefromxbm', 'palette', false, 'imagexbm' ),
		);

		if ( !isset( $typemap[$params['mimeType']] ) ) {
			$err = 'Image type not supported';
			wfDebug( "$err\n" );
			$errMsg = wfMessage( 'thumbnail_image-type' )->text();

			return $this->getMediaTransformError( $params, $errMsg );
		}
		list( $loader, $colorStyle, $useQuality, $saveType ) = $typemap[$params['mimeType']];

		if ( !function_exists( $loader ) ) {
			$err = "Incomplete GD library configuration: missing function $loader";
			wfDebug( "$err\n" );
			$errMsg = wfMessage( 'thumbnail_gd-library', $loader )->text();

			return $this->getMediaTransformError( $params, $errMsg );
		}

		if ( !file_exists( $params['srcPath'] ) ) {
			$err = "File seems to be missing: {$params['srcPath']}";
			wfDebug( "$err\n" );
			$errMsg = wfMessage( 'thumbnail_image-missing', $params['srcPath'] )->text();

			return $this->getMediaTransformError( $params, $errMsg );
		}

		$src_image = call_user_func( $loader, $params['srcPath'] );

		$rotation = function_exists( 'imagerotate' ) && !isset( $params['disableRotation'] )  ? $this->getRotation( $image ) : 0;
		list( $width, $height ) = $this->extractPreRotationDimensions( $params, $rotation );
		$dst_image = imagecreatetruecolor( $width, $height );

		// Initialise the destination image to transparent instead of
		// the default solid black, to support PNG and GIF transparency nicely
		$background = imagecolorallocate( $dst_image, 0, 0, 0 );
		imagecolortransparent( $dst_image, $background );
		imagealphablending( $dst_image, false );

		if ( $colorStyle == 'palette' ) {
			// Don't resample for paletted GIF images.
			// It may just uglify them, and completely breaks transparency.
			imagecopyresized( $dst_image, $src_image,
				0, 0, 0, 0,
				$width, $height,
				imagesx( $src_image ), imagesy( $src_image ) );
		} else {
			imagecopyresampled( $dst_image, $src_image,
				0, 0, 0, 0,
				$width, $height,
				imagesx( $src_image ), imagesy( $src_image ) );
		}

		if ( $rotation % 360 != 0 && $rotation % 90 == 0 ) {
			$rot_image = imagerotate( $dst_image, $rotation, 0 );
			imagedestroy( $dst_image );
			$dst_image = $rot_image;
		}

		imagesavealpha( $dst_image, true );

		$funcParams = array( $dst_image, $params['dstPath'] );
		if ( $useQuality && isset( $params['quality'] ) ) {
			$funcParams[] = $params['quality'];
		}
		call_user_func_array( $saveType, $funcParams );

		imagedestroy( $dst_image );
		imagedestroy( $src_image );

		return false; # No error
	}

	/**
	 * Callback for transformGd when transforming jpeg images.
	 */
	// FIXME: transformImageMagick() & transformImageMagickExt() uses JPEG quality 80, here it's 95?
	static function imageJpegWrapper( $dst_image, $thumbPath, $quality = 95 ) {
		imageinterlace( $dst_image );
		imagejpeg( $dst_image, $thumbPath, $quality );
	}

	/**
	 * Returns whether the current scaler supports rotation (im and gd do)
	 *
	 * @return bool
	 */
	public function canRotate() {
		$scaler = $this->getScalerType( null, false );
		switch ( $scaler ) {
			case 'im':
				# ImageMagick supports autorotation
				return true;
			case 'imext':
				# Imagick::rotateImage
				return true;
			case 'gd':
				# GD's imagerotate function is used to rotate images, but not
				# all precompiled PHP versions have that function
				return function_exists( 'imagerotate' );
			default:
				# Other scalers don't support rotation
				return false;
		}
	}

	/**
	 * @see $wgEnableAutoRotation
	 * @return bool Whether auto rotation is enabled
	 */
	public function autoRotateEnabled() {
		global $wgEnableAutoRotation;

		if ( $wgEnableAutoRotation === null ) {
			// Only enable auto-rotation when we actually can
			return $this->canRotate();
		}

		return $wgEnableAutoRotation;
	}

	/**
	 * @param File $file
	 * @param array $params Rotate parameters.
	 *   'rotation' clockwise rotation in degrees, allowed are multiples of 90
	 * @since 1.21
	 * @return bool
	 */
	public function rotate( $file, $params ) {
		global $wgImageMagickConvertCommand;

		$rotation = ( $params['rotation'] + $this->getRotation( $file ) ) % 360;
		$scene = false;

		$scaler = $this->getScalerType( null, false );
		switch ( $scaler ) {
			case 'im':
				$cmd = wfEscapeShellArg( $wgImageMagickConvertCommand ) . " " .
					wfEscapeShellArg( $this->escapeMagickInput( $params['srcPath'], $scene ) ) .
					" -rotate " . wfEscapeShellArg( "-$rotation" ) . " " .
					wfEscapeShellArg( $this->escapeMagickOutput( $params['dstPath'] ) );
				wfDebug( __METHOD__ . ": running ImageMagick: $cmd\n" );
				wfProfileIn( 'convert' );
				$retval = 0;
				$err = wfShellExecWithStderr( $cmd, $retval );
				wfProfileOut( 'convert' );
				if ( $retval !== 0 ) {
					$this->logErrorForExternalProcess( $retval, $err, $cmd );

					return new MediaTransformError( 'thumbnail_error', 0, 0, $err );
				}

				return false;
			case 'imext':
				$im = new Imagick();
				$im->readImage( $params['srcPath'] );
				if ( !$im->rotateImage( new ImagickPixel( 'white' ), 360 - $rotation ) ) {
					return new MediaTransformError( 'thumbnail_error', 0, 0,
						"Error rotating $rotation degrees" );
				}
				$result = $im->writeImage( $params['dstPath'] );
				if ( !$result ) {
					return new MediaTransformError( 'thumbnail_error', 0, 0,
						"Unable to write image to {$params['dstPath']}" );
				}

				return false;
			default:
				return new MediaTransformError( 'thumbnail_error', 0, 0,
					"$scaler rotation not implemented" );
		}
	}
}
