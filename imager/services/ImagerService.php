<?php
namespace Craft;

/**
 * Class ImagerService
 * @package Craft
 */

use Tinify;

class ImagerService extends BaseApplicationComponent
{
  var $imageDriver = 'gd';
  var $imagineInstance = null;
  var $imageInstance = null;
  var $configModel = null;
  
  // translate dictionary for translating transform keys into filename markers
  public static $transformKeyTranslate = array(
    'width'=>'W', 
    'height'=>'H', 
    'mode'=>'M', 
    'position' => 'P', 
    'format' => 'F', 
    'cropZoom' => 'CZ',
    'effects' => 'E',
    'preEffects' => 'PE',
    'resizeFilter' => 'RF', 
    'allowUpscale' => 'upscale', 
    'pngCompressionLevel' => 'PNGC', 
    'jpegQuality' => 'Q',
    'instanceReuseEnabled' => 'REUSE',
  );
  
  // translate dictionary for resize method 
  public static $filterKeyTranslate = array(
    'point'=>\Imagine\Image\ImageInterface::FILTER_POINT, 
    'box'=>\Imagine\Image\ImageInterface::FILTER_BOX, 
    'triangle'=>\Imagine\Image\ImageInterface::FILTER_TRIANGLE, 
    'hermite'=>\Imagine\Image\ImageInterface::FILTER_HERMITE, 
    'hanning'=>\Imagine\Image\ImageInterface::FILTER_HANNING, 
    'hamming'=>\Imagine\Image\ImageInterface::FILTER_HAMMING, 
    'blackman'=>\Imagine\Image\ImageInterface::FILTER_BLACKMAN, 
    'gaussian'=>\Imagine\Image\ImageInterface::FILTER_GAUSSIAN, 
    'quadratic'=>\Imagine\Image\ImageInterface::FILTER_QUADRATIC, 
    'cubic'=>\Imagine\Image\ImageInterface::FILTER_CUBIC, 
    'catrom'=>\Imagine\Image\ImageInterface::FILTER_CATROM, 
    'mitchell'=>\Imagine\Image\ImageInterface::FILTER_MITCHELL, 
    'lanczos'=>\Imagine\Image\ImageInterface::FILTER_LANCZOS, 
    'bessel'=>\Imagine\Image\ImageInterface::FILTER_BESSEL, 
    'sinc'=>\Imagine\Image\ImageInterface::FILTER_SINC, 
    
    );
  
  // translate dictionary for translating crafts built in position constants into relative format (width/height offset) 
  public static $craftPositonTranslate = array(
    'top-left' => '0% 0%',
    'top-center' => '50% 0%',
    'top-right' => '100% 0%',
    'center-left' => '0% 50%',
    'center-center' => '50% 50%',
    'center-right' => '100% 50%',
    'bottom-left' => '0% 100%',
    'bottom-center' => '50% 100%',
    'bottom-right' => '100% 100%'
    );

	/**
	 * Constructor
	 */
  public function __construct()
	{
		$extension = mb_strtolower(craft()->config->get('imageDriver'));

		if ($extension === 'gd') { // set in config
      $this->imageDriver = 'gd';
		} else if ($extension === 'imagick') {
      $this->imageDriver = 'imagick';
		} else { // autodetect
			if (craft()->images->isGd())
			{
        $this->imageDriver = 'gd';
			}
			else
			{
        $this->imageDriver = 'imagick';
				
			}
		}
    
    $this->_createImagineInstance();
	}
  

  /**
   * Creates the Imagine instance depending on the image driver
   */
  private function _createImagineInstance() {
    if ($this->imageDriver==='gd') {
      $this->imagineInstance = new \Imagine\Gd\Imagine();
    } else if ($this->imageDriver==='imagick') {
      $this->imagineInstance = new \Imagine\Imagick\Imagine();
    }
  }
  
  
	/**
	 * Do an image transform
	 *
	 * @param AssetFileModel|string $image
	 * @param AssetFileModel $transform
	 *
	 * @throws Exception
	 * @return Image
	 */
  public function transformImage($image, $transform, $configOverrides) {
    if (!$image) {
      return null; // there's nothing to see here, move along.
    }
    
    $this->configModel = new Imager_ConfigModel($configOverrides);
    
    $this->_createImagineInstance();
    
    if (is_string($image)) {
      // ok, so it's not an AssetFileModel. What is it then? 
      $imageString = str_replace($this->getSetting('imagerUrl'), '', $image);
      
      if (strrpos($imageString, 'http')!==false) {
        // todo : download remote file and proceed 
        throw new Exception(Craft::t('External urls are not yet supported.'));
      } else {
        $pathParts = pathinfo($imageString);
        $sourcePath = $this->getSetting('imagerSystemPath') . $pathParts['dirname'] . '/';
        $targetPath = $this->getSetting('imagerSystemPath') . $pathParts['dirname'] . '/';
        $targetUrl = $this->getSetting('imagerUrl') . $pathParts['dirname'] . '/';
        $imageFilename = $pathParts['basename'];
      }
      
    } else {
      // This is an AssetsFileModel! That's great.
      
      // todo : But only local sources are supported for now.
      if ($image->getSource()->type != 'Local') {
          throw new Exception(Craft::t('Only local asset sources are supported for now'));
      }
      
      $sourcePath = craft()->config->parseEnvironmentString($image->getSource()->settings['path']) . $image->getFolder()->path;
      $targetPath = $this->getSetting('imagerSystemPath') . $image->getFolder()->path;
      $targetUrl = $this->getSetting('imagerUrl') . $image->getFolder()->path;
      $imageFilename = $image->filename;
    }
    
    /**
     * Check all the things that could go wrong(tm)
     */
    if (!IOHelper::getRealPath($sourcePath)) {
      throw new Exception(Craft::t('Source folder “{sourcePath}” does not exist', array('sourcePath' => $sourcePath)));
    }

    if (!IOHelper::getRealPath($targetPath)) {
      IOHelper::createFolder($this->getSetting('imagerSystemPath') . $image->getFolder()->path, craft()->config->get('defaultFolderPermissions'), true);
			$targetPath = IOHelper::getRealPath($this->getSetting('imagerSystemPath') . $image->getFolder()->path);
      
      if (!IOHelper::getRealPath($targetPath)) {
        throw new Exception(Craft::t('Target folder “{targetPath}” does not exist and could not be created', array('targetPath' => $targetPath)));
      }
    }

    if ($targetPath && !IOHelper::isWritable($targetPath)) {
      throw new Exception(Craft::t('Target folder “{targetPath}” is not writeable', array('targetPath' => $targetPath)));
    }

    if (!IOHelper::fileExists($sourcePath . $imageFilename)) {
      throw new Exception(Craft::t('Requested image “{fileName}” does not exist in path “{sourcePath}”', array('fileName'=> $imageFilename, 'sourcePath' => $sourcePath)));
    }
    
    if (!craft()->images->checkMemoryForImage($sourcePath . $imageFilename))
		{
			throw new Exception(Craft::t("Not enough memory available to perform this image operation."));
		}
    
    
    /**
     * Transform can be either an array or just an object. 
     * Act accordingly and return the results the same way to the template.
     */
    $r = null;
    
    if (isset($transform[0])) { 
      $transformedImages = array();
      foreach ($transform as $t) {
        $transformedImage = $this->_getTransformedImage($imageFilename, $sourcePath, $targetPath, $targetUrl, $t);
        $transformedImages[] = $transformedImage;
      }
      $r = $transformedImages;
    } else { 
      $transformedImage = $this->_getTransformedImage($imageFilename, $sourcePath, $targetPath, $targetUrl, $transform);
      $r = $transformedImage;
    }
    
    $this->imageInstance = null; 
    return $r;
  }

  
  /**
  * Loads an image from a file system path, do transform, return transformed image as an Imager_ImageModel
  *
  * @param AssetFileModel $image
  * @param string $sourcePath
  * @param string $targetPath
  * @param string $targetUrl
  * @param Array $transform
  *
  * @throws Exception
  * @return Imager_ImageModel
  */
  private function _getTransformedImage($imageFilename, $sourcePath, $targetPath, $targetUrl, $transform) {
    // break up the image filename to get extension and actual filename 
    $pathParts = pathinfo($imageFilename);
    $targetExtension = $pathParts['extension'];
    $filename = $pathParts['filename'];
    
    // do we want to output in a certain format?
    if (isset($transform['format'])) {
      $targetExtension = $transform['format'];
    } 
    
    // normalize the transform before doing anything more 
    $transform = $this->_normalizeTransform($transform);
    
    // create target filename, path and url
    $targetFilename = $this->_createTargetFilename($filename, $targetExtension, $transform);
    $targetFilePath = $targetPath . $targetFilename;
    $targetFileUrl = $targetUrl . $targetFilename;
    
    /**
     * Check if the image already exists, if caching is turned on or if the cache has expired.
     */
    if (!$this->getSetting('cacheEnabled', $transform) || !IOHelper::fileExists($targetFilePath) || (IOHelper::getLastTimeModified($targetFilePath)->format('U') + $this->getSetting('cacheDuration') < time())) { 
      // create the imageInstance. only once if reuse is enabled, or always
      if (!$this->getSetting('instanceReuseEnabled', $transform) || $this->imageInstance==null) {
        $this->imageInstance = $this->imagineInstance->open($sourcePath . $imageFilename);
      }
      
      // Apply any pre resize filters
      if (isset($transform['preEffects'])) {
        $this->_applyImageEffects($this->imageInstance, $transform['preEffects']);
      }
      
      $originalSize = $this->imageInstance->getSize();
      $cropSize = $this->_getCropSize($originalSize, $transform); 
      $resizeSize = $this->_getResizeSize($originalSize, $transform);
      $saveOptions = $this->_getSaveOptions($targetExtension, $transform);
      $filterMethod = $this->_getFilterMethod($transform);
      
      if (!isset($transform['mode']) || mb_strtolower($transform['mode'])=='crop' || mb_strtolower($transform['mode'])=='croponly') {
        $cropPoint = $this->_getCropPoint($resizeSize, $cropSize, $transform);
        $this->imageInstance->resize($resizeSize, $filterMethod)->crop($cropPoint, $cropSize);
      } else {
        $this->imageInstance->resize($resizeSize, $filterMethod);
      }
      
      // Apply post resize filters
      if (isset($transform['effects'])) {
        $this->_applyImageEffects($this->imageInstance, $transform['effects']);
      }
      
      $this->imageInstance->save($targetFilePath, $saveOptions);
      
      // if file was created, check if optimization should be done
      if (IOHelper::fileExists($targetFilePath)) {
        if (($targetExtension=='jpg' || $targetExtension=='jpeg') && $this->getSetting('jpegoptimEnabled', $transform)) {
          $this->runJpegoptim($targetFilePath, $transform);
        }
        if ($targetExtension=='png' && $this->getSetting('optipngEnabled', $transform)) {
          $this->runOptipng($targetFilePath, $transform);
        }
        if ($this->getSetting('tinyPngEnabled', $transform)) {
          $this->runTinyPng($targetFilePath, $transform);
        }
      }
    }
    
    $imageInfo = @getimagesize($targetFilePath);
    
    $imagerImage = new Imager_ImageModel();
    $imagerImage->url = $targetFileUrl;
    $imagerImage->width = $imageInfo[0];
    $imagerImage->height = $imageInfo[1];

    return $imagerImage;
  }


  /**
   * Creates the target filename
   * 
   * @param string $filename
   * @param string $extension
   * @param string $transformFileString
   * @return string
   */
  private function _createTargetFilename($filename, $extension, $transform) {
    $hashFilename = $this->getSetting('hashFilename', $transform);
    
    // generate unique string base on transform
    $transformFileString = $this->_createTransformFilestring($transform);
    $configOverridesString = $this->configModel->getConfigOverrideString();
    
    if ($hashFilename) {
      if (is_string($hashFilename)) {
        if ($hashFilename=='postfix') {
          return  $filename . '_' . md5($transformFileString . $configOverridesString) . '.' . $extension;
        } else {
          return md5($filename . $transformFileString . $configOverridesString) . '.' . $extension;
        }
      } else {
        return md5($filename . $transformFileString . $configOverridesString) . '.' . $extension;
      }
    } else {
      return $filename . $transformFileString . $configOverridesString . '.' . $extension;
    }
  }

  
  /**
   * Normalize transform object and values
   * 
   * @param $transform
   * @return mixed
   */
  private function _normalizeTransform($transform) {
    if (isset($transform['mode']) && (($transform['mode']!='crop') && ($transform['mode']!='croponly'))) {
      if (isset($transform['position'])) {
        unset($transform['position']);
      }
    }
    
    if (isset($transform['quality'])) {
      $value = $transform['quality'];
      unset($transform['quality']);
      
      if (!isset($transform['jpegQuality'])) {
        $transform['jpegQuality'] = $value;
      }
    }
    
    if (isset($transform['format'])) {
      unset($transform['format']);
    }

    if (isset($transform['position'])) {
      if (isset(ImagerService::$craftPositonTranslate[$transform['position']])) {
        $transform['position'] = ImagerService::$craftPositonTranslate[$transform['position']];
      } else {
        $transform['position'] = str_replace('%', '', $transform['position']);
      }
    }
    
    ksort($transform);
    
    // todo : move width and height to front of filename for sanitys sake? And effects to the end?
    
    return $transform;
  }
  
  
  /**
   * Creates additional file string that is appended to filename 
   * 
   * @param $transform
   * @return string
   */
  private function _createTransformFilestring($transform) {
    $r = '';
    
    foreach ($transform as $k=>$v) {
      if ($k=='effects' || $k=='preEffects') {
        $effectString = '';
        foreach ($v as $eff=>$param) {
          $effectString .= '_'. $eff . '-' . (is_array($param) ? implode("-", $param) : $param);
        }
        
        $r .= '_' . (isset(ImagerService::$transformKeyTranslate[$k]) ? ImagerService::$transformKeyTranslate[$k] : $k) . $effectString; 
      } else {
        $r .= '_' . (isset(ImagerService::$transformKeyTranslate[$k]) ? ImagerService::$transformKeyTranslate[$k] : $k) . (is_array($v) ? implode("-", $v) : $v);
      }
    }
    
    return str_replace(array('#', '(', ')'), '', str_replace(array(' ', '.'), '-', $r));
  }
  

  /**
   * Creates the destination crop size box
   * 
   * @param \Imagine\Image\Box $originalSize
   * @param $transform
   * @return \Imagine\Image\Box
   */
  private function _getCropSize($originalSize, $transform) 
  {
    $width = $originalSize->getWidth();
    $height = $originalSize->getHeight();
    $aspect = $width / $height;
    
    if (isset($transform['width']) and isset($transform['height'])) 
    {
      $width = (int) $transform['width'];
      $height = (int) $transform['height'];
    } 
    else if (isset($transform['width'])) 
    {
      $width = (int) $transform['width'];
      $height = floor((int) $transform['width'] / $aspect);
    } 
    else if (isset($transform['height'])) 
    {
      $width = floor((int) $transform['height']*$aspect);
      $height = (int) $transform['height'];
    }
    
    // check if we want to upscale. If not, adjust the transform here 
    if (!$this->getSetting('allowUpscale', $transform)) {
      list($width, $height) = $this->_enforceMaxSize($width, $height, $originalSize);
    }
 
    return new \Imagine\Image\Box($width, $height);
  }
  
  
  /**
   * Creates the resize size box
   * 
   * @param \Imagine\Image\Box $originalSize
   * @param $transform
   * @return \Imagine\Image\Box
   */
  private function _getResizeSize($originalSize, $transform) 
  {
    $width = $originalSize->getWidth();
    $height = $originalSize->getHeight();
    $aspect = $width / $height;

    $mode = isset($transform['mode']) ? mb_strtolower($transform['mode']) : 'crop';
    
    if ($mode=='crop' || $mode=='fit') {

      if (isset($transform['width']) and isset($transform['height'])) {
        $transformAspect = (int)$transform['width'] / (int)$transform['height'];
        
        if ($mode=='crop') {
          
          $cropZoomFactor = $this->_getCropZoomFactor($transform);
          
          if ($transformAspect < $aspect) { // use height as guide
            $height = (int)$transform['height'] * $cropZoomFactor;
            $width = ceil($originalSize->getWidth() * ($height / $originalSize->getHeight()));
          } else { // use width
            $width = (int)$transform['width'] * $cropZoomFactor;
            $height = ceil($originalSize->getHeight() * ($width / $originalSize->getWidth()));
          }
          
        } else {
          
          if ($transformAspect > $aspect) { // use height as guide
            $height = (int)$transform['height'];
            $width = ceil($originalSize->getWidth() * ($height / $originalSize->getHeight()));
          } else { // use width
            $width = (int)$transform['width'];
            $height = ceil($originalSize->getHeight() * ($width / $originalSize->getWidth()));
          }
          
        }

      } else if (isset($transform['width'])) {

        $width = (int)$transform['width'];
        $height = ceil($width / $aspect);

      } else if (isset($transform['height'])) {
        
        $height = (int)$transform['height'];
        $width = ceil($height * $aspect);
        
      }
      
    } else if ($mode=='croponly') {
      $width = $originalSize->getWidth();
      $height = $originalSize->getHeight();
      
    } else if ($mode=='stretch') {
      $width = (int)$transform['width'];
      $height = (int)$transform['height'];
    }
    
    // check if we want to upscale. If not, adjust the transform here 
    if (!$this->getSetting('allowUpscale', $transform)) {
      list($width, $height) = $this->_enforceMaxSize($width, $height, $originalSize, $this->_getCropZoomFactor($transform));
    }
    
    return new \Imagine\Image\Box($width, $height);
  }

  
  /**
   * Enforces a max size if allowUpscale is false
   * 
   * @param $width
   * @param $height
   * @param $originalSize
   * @return array
   */
  private function _enforceMaxSize($width, $height, $originalSize, $zoomFactor = 1) {
    $adjustedWidth = $width;
    $adjustedHeight = $height;
    
    if ($width > $originalSize->getWidth()*$zoomFactor) {
      $adjustedWidth = floor($height * ($originalSize->getWidth()*$zoomFactor/$width));
      $adjustedHeight = $originalSize->getWidth()*$zoomFactor;
    }
    
    if ($height > $originalSize->getHeight()*$zoomFactor) {
      $adjustedWidth = floor($width * ($originalSize->getHeight()*$zoomFactor/$height));
      $adjustedHeight = $originalSize->getHeight()*$zoomFactor;
    }
    
    return array($adjustedWidth, $adjustedHeight);
  }
  

  /**
   * Get the crop zoom factor
   * 
   * @param $transform
   * @return float|int
   */
  private function _getCropZoomFactor($transform) {
    if (isset($transform['cropZoom'])) {
      return (float)$transform['cropZoom'];
    }
    return 1;
  }
  

  /**
   * Gets crop point
   * 
   * @param \Imagine\Image\Box $resizeSize
   * @param \Imagine\Image\Box $cropSize
   * @param $transform
   * @return \Imagine\Image\Point
   */
  private function _getCropPoint($resizeSize, $cropSize, $transform)
  {
    // find the "area of opportunity", the difference between the resized image size and the crop size
    $wDiff = $resizeSize->getWidth() - $cropSize->getWidth();
    $hDiff = $resizeSize->getHeight() - $cropSize->getHeight();
    
    // get default crop position from the settings
    $position = $this->getSetting('position', $transform);
    
    // get the offsets, left and top, now as an int, representing the % offset
    list($leftOffset, $topOffset) = explode(' ', str_replace('%', '', $position)); 
    
    // calculate and return the point
    return new \Imagine\Image\Point(floor($wDiff * ($leftOffset/100)), floor($hDiff * ($topOffset/100)));
  }  
  
  
  /**
   * Returns the filter method for resize operations
   * 
   * @return string
   */
  private function _getFilterMethod($transform) 
  {
    return $this->imageDriver=='imagick' ? ImagerService::$filterKeyTranslate[$this->getSetting('resizeFilter', $transform)] : \Imagine\Image\ImageInterface::FILTER_UNDEFINED;
  }


  /**
   * Get the save options based on extension and transform
   * 
   * @param $extension
   * @param $transform
   * @return array
   */
  private function _getSaveOptions($extension, $transform) 
  {
    switch ($extension) {
      case 'jpg':
      case 'jpeg':
        return array('jpeg_quality' => $this->getSetting('jpegQuality', $transform));
        break;
      case 'gif':
        return array('flatten' => false);
        break;
      case 'png':
        return array('png_compression_level' => $this->getSetting('pngCompressionLevel', $transform));
        break;
    }
    return array();
  }
  
  
  /**
   * ---- Effects ------------------------------------------------------------------------------------------------------
   */
  
  
  /**
   * Apply effects array to image instance
   * 
   * @param $imageInstance
   * @param $effects
   */
  private function _applyImageEffects($imageInstance, $effects) {
    // apply effects in the order they were entered
    foreach ($effects as $effect=>$value) {
      
      $effect = mb_strtolower($effect);
      
      if ($effect == 'grayscale' || $effect == 'greyscale') { // we do not participate in that quarrel
        $imageInstance->effects()->grayscale();
      }
      
      if ($effect == 'negative') {
        $imageInstance->effects()->negative();
      }
      
      if ($effect == 'blur') { 
        $imageInstance->effects()->blur(is_int($value) || is_float($value) ? $value : 1);
      }
      
      if ($effect == 'sharpen') { 
        $imageInstance->effects()->sharpen();
      }
      
      if ($effect == 'gamma') { 
        $imageInstance->effects()->gamma(is_int($value) || is_float($value) ? $value : 1);
      }
      
      if ($effect == 'colorize') {
        $color = $imageInstance->palette()->color($value);
        $imageInstance->effects()->colorize($color);
      }

      /**
       * Effects that are imagick only. Will be ignored if GD is used
       */
      if ($this->imageDriver=='imagick') {
        $imagickInstance = $imageInstance->getImagick();
          
        // colorBlend is almost like colorize, but works with alpha and use blend modes.
        if ($effect == 'colorblend') {
          
          if (is_array($value)) {
            if (count($value)>1) {
              
              $this->_colorBlend($imagickInstance, $value[0], $value[1]);
            } else {
              $this->_colorBlend($imagickInstance, $value[0], 1);
            }
          } else {
            $this->_colorBlend($imagickInstance, $value, 1);
          }
        }
        
        // sepia, just because it's there.
        if ($effect == 'sepia') {
          $imagickInstance->sepiaToneImage($value);
        }
        
        // tint
        if ($effect == 'tint' && is_array($value)) {
          $tint = new \ImagickPixel($value[0]);
          $opacity = new \ImagickPixel($value[1]);
          
          $imagickInstance->tintImage($tint, $opacity);
        }
        
        // contrast
        if ($effect == 'contrast') {
          if (is_int($value)) {
            for ($i=0; $i<abs($value); $i++) {
              if ($value>0) {
                $imagickInstance->contrastImage(true);
              } else {
                $imagickInstance->contrastImage(false);
              }
            }
          } else {
            $imagickInstance->contrastImage($value);
          }
        }
        
        // modulate
        if ($effect == 'modulate' && is_array($value) && count($value)==3) {
          $imagickInstance->modulateImage($value[0], $value[1], $value[2]);
        }
        
        // normalize
        if ($effect == 'normalize') {
          $imagickInstance->normalizeImage();
        }
        
        // contrast stretch
        if ($effect == 'contraststretch' && is_array($value) && count($value)>=2) {
          $imagickInstance->contrastStretchImage($value[0], $value[1]);
        }
        
        // vignette
        // todo : make a better vignette
        if ($effect == 'vignette' && is_array($value) && count($value)>=3) {
          $this->_vignette($imagickInstance, $value[0], $value[1], $value[2]);
        }
        
        // todo : implement unsharp mask
        
        // custom filter
        if ($effect == 'customfilter') {
          $this->_applyCustomFilter($imagickInstance, $value);
        }
        
      }
    }
  }

  /**
   * Applies a custom predefined filter to image
   * 
   * Heavily inspired by Dejan Marjanovics article http://code.tutsplus.com/tutorials/create-instagram-filters-with-php--net-24504
   * 
   * TODO : Move this out into seperate plugins through events?
   * 
   * @param $imagick
   * @param $filterName
   */
  private function _applyCustomFilter($imagick, $filterName) {
    $filterName = mb_strtolower($filterName);
    
    if ($filterName=='gotham') {
      $imagick->modulateImage(120, 10, 100);
      $imagick->colorizeImage('#222b96', 1);
      $imagick->gammaImage(0.6);
      $imagick->contrastImage(10);
    }
    
    if ($filterName=='toaster') {

      $this->_colortone($imagick, '#330000', 100, 0);
      $imagick->modulateImage(158, 80, 100);
      $imagick->gammaImage(1.1);
      $imagick->contrastImage(-100);
      
      $this->_vignette($imagick, 'none', 'LavenderBlush3');
      $this->_vignette($imagick, '#ff9966', 'none');
    }
    
  }
  

  /**
   * Color blend filter, more advanced version of colorize.
   *
   * Code by olav@redwall.ee on http://php.net/manual/en/imagick.colorizeimage.php
   * 
   * @param $imageInstance
   * @param $color
   * @param int $alpha
   * @param int $composite_flag
   */
  private function _colorBlend($imagickInstance, $color, $alpha = 1, $composite_flag = \Imagick::COMPOSITE_COLORIZE) {
    $draw = new \ImagickDraw();
    $draw->setFillColor($color);

    $width = $imagickInstance->getImageWidth();
    $height = $imagickInstance->getImageHeight();

    $draw->rectangle(0, 0, $width, $height);

    $temporary = new \Imagick();
    $temporary->setBackgroundColor(new \ImagickPixel('transparent'));
    $temporary->newImage($width, $height, new \ImagickPixel('transparent'));
    $temporary->setImageFormat('png32');
    $temporary->drawImage($draw);

    $alphaChannel = $imagickInstance->clone();
    $alphaChannel->setImageAlphaChannel(\Imagick::ALPHACHANNEL_EXTRACT);
    $alphaChannel->negateImage(false, \Imagick::CHANNEL_ALL);
    $imagickInstance->setImageClipMask($alphaChannel);

    $clone = $imagickInstance->clone();
    $clone->compositeImage($temporary, $composite_flag, 0, 0);
    $clone->setImageOpacity($alpha);

    $imagickInstance->compositeImage($clone, \Imagick::COMPOSITE_DEFAULT, 0, 0);
  }
  

  /**
   * Create a vignette
   * 
   * Heavily inspired by Dejan Marjanovics article http://code.tutsplus.com/tutorials/create-instagram-filters-with-php--net-24504
   * 
   * @param $imagickInstance
   * @param string $color1
   * @param string $color2
   * @param float $crop_factor
   */
  
  private function _vignette($imagickInstance, $color1 = 'none', $color2 = 'black', $crop_factor = 1.5) {
    $vignetteWidth = floor($imagickInstance->getImageWidth() * $crop_factor);
    $vignetteHeight = floor($imagickInstance->getImageHeight() * $crop_factor);
     
    $radial = new \Imagick();
    $radial->newPseudoImage($vignetteWidth, $vignetteHeight, "radial-gradient:$color1-$color2");
    $radial->setImageFormat('png32');
    
    $imagickInstance->compositeImage($radial, \imagick::COMPOSITE_MULTIPLY, -($vignetteWidth-$imagickInstance->getImageWidth())/2, -($vignetteHeight-$imagickInstance->getImageHeight())/2);
  }
  
  
  /**
   * ---- Optimizations ------------------------------------------------------------------------------------------------------
   */

  /**
   * Run jpegoptim on file
   * 
   * @param $file
   * @param $transform
   */
  private function runJpegoptim($file, $transform) {
    $cmd = $this->getSetting('jpegoptimPath', $transform);
    $cmd .= ' ';
    $cmd .= $this->getSetting('jpegoptimOptionString', $transform);
    $cmd .= ' ';
    $cmd .= $file;
    
    $this->executeOptimize($cmd, $file);
  }
  
  /**
   * Run optipng on file
   * 
   * @param $file
   * @param $transform
   */
  private function runOptipng($file, $transform) {
    $cmd = $this->getSetting('optipngPath', $transform);
    $cmd .= ' ';
    $cmd .= $this->getSetting('optipngOptionString', $transform);
    $cmd .= ' ';
    $cmd .= $file;
    
    $this->executeOptimize($cmd, $file);
  }

  private function runTinyPng($file, $transform) {
    $this->makeTask('Imager_TinyPng', $file);
  }

  
  /**
   * Executes a shell command
   * 
   * @param $command
   */
  public function executeOptimize($command, $file='') {
    $command = escapeshellcmd($command);
    $r = shell_exec($command);
    
    if ($this->getSetting('logOptimizations')) {
      ImagerPlugin::log("Optimized image $file \n\n" . $r, LogLevel::Info, true);
    }
  }
  
  
  /**
   * ---- Settings ------------------------------------------------------------------------------------------------------
   */

	/**
	 * Gets a plugin setting
   * 
	 * @param $name String Setting name
	 * @return mixed Setting value
	 * @author André Elvan
	*/
  public function getSetting($name, $transform = null) {
    if ($this->configModel===null) {
      $this->configModel = new Imager_ConfigModel();
    }
    
    return $this->configModel->getSetting($name, $transform);
  }
  
  
  /**
	 * Registers a Task with Craft, taking into account if there is already one pending
	 *
	 * @method makeTask
	 * @param string $taskName The name of the Task you want to register
	 * @param array $paths An array of paths that should go in that Tasks settings
	 */
	public function makeTask($taskName, $paths)
	{
		// If there are any pending tasks, just append the paths to it
		$task = craft()->tasks->getNextPendingTask($taskName);
		if ($task && is_array($task->settings))
		{
			$settings = $task->settings;
			if (!is_array($settings['paths']))
			{
				$settings['paths'] = array($settings['paths']);
			}
			if (is_array($paths))
			{
				$settings['paths'] = array_merge($settings['paths'], $paths);
			}
			else
			{
				$settings['paths'][] = $paths;
			}
      
			// Make sure there aren't any duplicate paths
			$settings['paths'] = array_unique($settings['paths']);
      
			// Set the new settings and save the task
			$task->settings = $settings;
			craft()->tasks->saveTask($task, false);
		}
		else
		{
			craft()->tasks->createTask($taskName, null, array(
				'paths' => $paths
			));
		}
	}
  
}
