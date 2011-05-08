<?php
// vim: exandtab softabstop=4 tabstop=4 shiftwidth=4

/**
 * Erskine Design ImageResizer (PHP5 only)
 * REQUIRES ExpressionEngine 2+
 * 
 * @package     ED_ImageResizer
 * @version     1.0.0
 * @author      Glen Swinfield (Erskine Design)
 * @copyright   Copyright (c) 2009 Erskine Design
 * @license     http://creativecommons.org/licenses/by-sa/3.0/ Attribution-Share Alike 3.0 Unported
 * 
 */
 
$plugin_info = array(   'pi_name'           => 'EE2 ED Image Resizer',
                        'pi_version'        => '1.0.0',
                        'pi_author'         => 'Erskine Design',
                        'pi_author_url'     => 'http://github.com/erskinedesign/',
                        'pi_description'    => 'Resizes and caches images on the fly',
                        'pi_usage'          => Ed_imageresizer::usage());

/**
 * Ed_imageresizer For EE2.0
 * 
 * @package     ED_ImageResizer
 * @author      Erskine Design
 * @version     1.0.0
 * 
 */
Class Ed_imageresizer
{
    
    public     $return_data     = '';           // return data (from constructor)
    
    /**
     * Vars set in tag params
     */
    private $debug              = FALSE;    // produce error messages
    private $image              = '';       // The raw image (should be /images/folder/image.gif)
    private $quality            = '100';    // Image quality;
    private $forceHeight        = FALSE;    // do we want to force the height of the imahe to maxHeight
    private $forceWidth         = FALSE;    // do we want to force the width to maxWidth
    private $width              = '';       // the width of the original image
    private $height             = '';       // the height of the original image
    private $size               = '';       // array: original dimensions of image
    private $mime               = '';       // the mime type of the original image 
    private $data               = '';       // Holds image data
    private $cropratio          = '';       // crop ratio (if there is one)
    private $offsetX            = 0;        // x offset (crop)
    private $offsetY            = 0;        // y offset (crop)
    private $class              = '';       // image tag class
    private $title              = '';       // image tag title
    private $alt                = '';       // image tag alt
    private $id                 = '';       // image tag id
    private $default_image      = '';       // the default image to use if one is not passed into the params
    private $href_only          = '';       // only return the path to the file, not the full tag.
    private $ext                = '';
    
    // ADD PATHS TO YOUR WEB ROOT AND CACHE FOLDER HERE
    private $server_path        = ''; // no trailing slash
    private $cache_path         = ''; // with trailing slash
    
    private $memory_limit       = '36M'; // the memory limit to set

    /**
     * Constructor
     */
    public function Ed_imageresizer( )
    {
        $EE =& get_instance();
        $EE->load->library('typography'); 
        
        $this->forceWidth     = $EE->TMPL->fetch_param('forceWidth') != 'yes' ? FALSE : TRUE;
        $this->forceHeight    = $EE->TMPL->fetch_param('forceHeight') != 'yes' ? FALSE : TRUE;
        $this->image          = $EE->typography->parse_file_paths(preg_replace('/^(s?f|ht)tps?:\/\/[^\/]+/i', '', (string) html_entity_decode($EE->TMPL->fetch_param('image'))));
        $this->maxWidth       = $EE->TMPL->fetch_param('maxWidth') != '' ?   (int) $EE->TMPL->fetch_param('maxWidth')  : 0;
        $this->maxHeight      = $EE->TMPL->fetch_param('maxHeight') != '' ?  (int) $EE->TMPL->fetch_param('maxHeight') : 0;
        $this->color          = $EE->TMPL->fetch_param('color') != '' ? preg_replace('/[^0-9a-fA-F]/', '', (string) $EE->TMPL->fetch_param('color')) : FALSE;
        $this->cropratio      = $EE->TMPL->fetch_param('cropratio');
        $this->class          = $EE->TMPL->fetch_param('class');
        $this->title          = $EE->TMPL->fetch_param('title');
        $this->id             = $EE->TMPL->fetch_param('id');
        $this->alt            = $EE->TMPL->fetch_param('alt');
        $this->default_image  = (string) html_entity_decode($EE->TMPL->fetch_param('default'));
        $this->href_only      = $EE->TMPL->fetch_param('href_only');
        $this->debug          = $EE->TMPL->fetch_param('debug') != 'yes' ? false : true;
            
        /**
         * Load in cache path and server path from config if they exist
         *
         */
        if ( ! $this->server_path ) $this->server_path = $EE->config->item('ed_server_path');
        if ( ! $this->cache_path ) $this->cache_path = $EE->config->item('ed_cache_path');
            
        $error_string = '<div style="background:#f00; color:#fff; font:bold 11px verdana; padding:12px; border:2px solid #000">%s</div>';

        if( $this->cache_path == '' || $this->server_path == '' ) {
            if($this->debug) {
                $this->return_data = sprintf($error_string, 'The cache and server paths need to be set in your config file.');
            }
            else {
                $this->return_data = '';
            }
            return;
        }

        $ret = $this->_run();

        // error
        if ( is_array($ret) && $this->debug ) {
            $this->return_data = sprintf($error_string, $ret[2]);
            return;
        }
        elseif( is_array($ret)) {
            return;
        }
        
        $this->return_data = $ret;
        return;
    }
    
    /**
     * Runs all the elemenst of the resize process and returns the result.
     *
     */
    private function _run(){

        // are all paths writeable
        if (!file_exists($this->cache_path)) {
            if(!mkdir($this->cache_path, 0755)){
                return array(false, 'fatal', 'Cache path is does not exist and cannot be created. Path: '.$this->cache_path);
            }
        }
        
        if (!is_readable($this->cache_path)) {
            return array(false, 'fatal', 'Cache path is not readable. Path: '.$this->cache_path);
        }
        
        if(!is_writable($this->cache_path)) {
            return array(false, 'fatal', 'Cache path is not writable. Path: '.$this->cache_path);
        }
        // ----
        
        // is there an image, if ot try to use default
        if($this->image == '' && $this->default_image == '') {
            return array(false, 'fatal', 'No image or default image are set.');
        }
        elseif( $this->image == '' && $this->default_image != '' ) {
            $this->image = $this->default_image;
        }
        
        // if we get here an image has been set so make sure it starts with a slash.
        if($this->image{0} != '/') { $this->image = '/'.$this->image;}
            
        // For security, directories cannot contain ':', images cannot contain '..' or '<', and images must start with '/'
        if (strpos(dirname($this->image), ':') || preg_match('/(\.\.|<|>)/', $this->image)) {
            return array(false, 'fatal', 'Image path is invalid. Image: '.$this->image);
        }
            
        // If the image doesn't exist, or we haven't been told what it is, there's nothing that we can do
        if(!file_exists($this->server_path . $this->image)) {
            return array(false, 'fatal', 'The image does not exist. Server path: '.$this->server_path . $this->image);
        }
        
        // Get the size and MIME type of the requested image
        $this->size        = GetImageSize($this->server_path . $this->image);
        $this->mime        = $this->size['mime'];
        
        // Make sure that the requested file is actually an image
        if (substr($this->mime, 0, 6) != 'image/') {
            return array(false, 'fatal', 'The mime type of the image to be resized is not an image. '.substr($this->mime, 0, 6));
        }
        
        $this->width    = $this->size[0];
        $this->height   = $this->size[1];

        // set the extension
        if(strstr($this->mime,'jpg') || strstr($this->mime,'jpeg')) {
            $this->ext = '.jpg';
        }
            
        if(strstr($this->mime,'gif')) {
            $this->ext = '.gif';
        }
        
        if(strstr($this->mime,'png')) {
            $this->ext = '.png';
        }
    
        // generate cached filename
        $this->resized = $this->cache_path . sha1($this->image . $this->forceWidth . $this->forceHeight . $this->color . $this->maxWidth . $this->maxHeight . $this->cropratio).$this->ext;

        // is already cached?
        if (file_exists($this->resized)) {
            return $this->_compileImgTag();
        }
        
        // calcualte the new dimensions that we are resizing to
        // after this call we will have $this->maxWidth and height
         // resize to a maximum height
        if ($this->maxHeight != '' && $this->maxWidth == '') {
            $this->maxWidth    = 99999999999999;
        }
        // resize to a maximum width
        elseif ($this->maxWidth != '' && $this->maxHeight == '') {
            $this->maxHeight    = 99999999999999;
        }
        // just coloring (colouring actually)
        elseif ($this->color != '' && $this->maxWidth == '' && $this->maxHeight == '') {
            $this->maxWidth   = $this->width;
            $this->maxHeight  = $this->height;
        }

        // we don't want to force width or height so compile the finished tag and return false
        if ( ( ( !$this->forceWidth && !$this->forceHeight ) && ( $this->maxWidth == '' && $this->maxHeight == '' ) ) || ( $this->color == '' && ($this->maxWidth >= $this->width) && ($this->maxHeight >= $this->height) && !$this->forceWidth && !$this->forceHeight ) ) {
            $this->lastModifiedString    = gmdate('D, d M Y H:i:s', filemtime($this->server_path . $this->image)) . ' GMT';
            $this->etag                  = md5($this->data);
            $this->resized               = $this->server_path.$this->image; // we haven't actually resized it
            return $this->_compileImgTag();
        }
 
        // if we get here we are going to need to try to do some resizing
        // so lets find out if we want to crop as well
        if($this->cropratio != '') {
            $this->_crop();
        }
            
        // if we get here then we have enough to be getting on with so get the resizing underway.
        $this->_resize();
 
        return $this->_compileImgTag();;
    
    }
            
    private function _crop(){
        
        $this->cropratio    = explode(':', $this->cropratio);
        
        if (count($this->cropratio) == 2) {
            
            $this->ratioComputed        = $this->width / $this->height;
            $this->cropRatioComputed    = (float) $this->cropratio[0] / (float) $this->cropratio[1];
                
            if ($this->ratioComputed < $this->cropRatioComputed) {
                // Image is too tall so we will crop the top and bottom
                $this->origHeight       = $this->height;
                $this->height           = $this->width / $this->cropRatioComputed;
                $this->offsetY          = ($this->origHeight - $this->height) / 2;
            } else if ($this->ratioComputed > $this->cropRatioComputed) {
                // Image is too wide so we will crop off the left and right sides
                $this->origWidth    = $this->width;
                $this->width        = $this->height * $this->cropRatioComputed;
                $this->offsetX      = ($this->origWidth - $this->width) / 2;
            }
        }
    }
    
    private function _resize() {
        
        // Setting up the ratios needed for resizing. We will compare these below to determine how to
        // resize the image (based on height or based on width)
        $this->xRatio        = $this->maxWidth / $this->width;
        $this->yRatio        = $this->maxHeight / $this->height;
        
        if ( ( ( $this->xRatio * $this->height ) < $this->maxHeight ) || $this->forceWidth === true )
        { // Resize the image based on width
            $this->tnHeight   = ceil($this->xRatio * $this->height);
            $this->tnWidth    = $this->maxWidth;
        }
        else // Resize the image based on height
        {
            $this->tnWidth   = ceil($this->yRatio * $this->width);
            $this->tnHeight  = $this->maxHeight;
        }
        
        // We don't want to run out of memory
        ini_set('memory_limit', $this->memory_limit);
        
        // Set up a blank canvas for our resized image (destination)
        $this->dst    = imagecreatetruecolor($this->tnWidth, $this->tnHeight);
        
        // Set up the appropriate image handling functions based on the original image's mime type
        switch ($this->mime)
        {
            case 'image/gif':
                // We will be converting GIFs to PNGs to avoid transparency issues when resizing GIFs
                // This is maybe not the ideal solution, but IE6 can suck it
                $this->creationFunction = 'ImageCreateFromGif';
                $this->outputFunction   = 'ImagePng';
                $this->mime             = 'image/png'; // We need to convert GIFs to PNGs
                $this->doSharpen        = FALSE;
                $this->quality          = round(10 - ($this->quality / 10)); // We are converting the GIF to a PNG and PNG needs a compression level of 0 (no compression) through 9
            break;
            
            case 'image/x-png':
            case 'image/png':
                $this->creationFunction = 'ImageCreateFromPng';
                $this->outputFunction   = 'ImagePng';
                $this->doSharpen        = FALSE;
                $this->quality          = round(10 - ($this->quality / 10)); // PNG needs a compression level of 0 (no compression) through 9
            break;
            
            default:
                $this->creationFunction = 'ImageCreateFromJpeg';
                $this->outputFunction   = 'ImageJpeg';
                $this->doSharpen        = FALSE;
            break;
        }
        
        // Read in the original image
        $function   = $this->creationFunction;
        $this->src  = $function($this->server_path . $this->image);

        if (in_array($this->mime, array('image/gif', 'image/png'))) {
            if ($this->color == '') {
                // If this is a GIF or a PNG, we need to set up transparency
                imagealphablending($this->dst, false);
                imagesavealpha($this->dst, true);
            }
            else {
                // Fill the background with the specified color for matting purposes
                if ($this->color[0] == '#')
                    $this->color = substr($this->color, 1);
                
                $this->background    = FALSE;
                
                if (strlen($this->color) == 6) {
                    $this->background    = imagecolorallocate($this->dst, hexdec($this->color[0].$this->color[1]), hexdec($this->color[2].$this->color[3]), hexdec($this->color[4].$this->color[5]));
                }
                else if (strlen($color) == 3) {
                    $this->background    = imagecolorallocate($this->dst, hexdec($this->color[0].$this->color[0]), hexdec($this->color[1].$this->color[1]), hexdec($this->color[2].$this->color[2]));
                }
                
                if ($this->background) {
                    imagefill($this->dst, 0, 0, $this->background);
                }
            }
        }
        
        // Resample the original image into the resized canvas we set up earlier
        ImageCopyResampled($this->dst, $this->src, 0, 0, $this->offsetX, $this->offsetY, $this->tnWidth, $this->tnHeight, $this->width, $this->height);
        $this->etag = $this->_saveFile();
    }
    
    private function _saveFile(){
        
        // Write the resized image to the cache
        $function = $this->outputFunction;
        $function($this->dst, $this->resized, $this->quality);
        
        // Clean up the memory
        ImageDestroy($this->src);
        ImageDestroy($this->dst);

    }

    /**
     * Compile the image tag
     *
     */
    private function _compileImgTag(){
        
        if($this->href_only == 'yes') {
            return str_replace($this->server_path, '', $this->resized);
        }
        
        $size = GetImageSize($this->resized);
                
        $width  = $size[0];
        $height = $size[1];

        $this->image_tag = '<img ';
        
        // add class, id alt and title
        if($this->class != '') {
            $this->image_tag .= 'class="'.$this->class.'" ';
        }
        
        if($this->id != '') {
            $this->image_tag .= 'id="'.$this->id.'" ';
        }
        
        if($this->title != '') {
            $this->image_tag .= 'title="'.$this->title.'" ';
        }
        
        if($this->alt != '') {
            $this->image_tag .= 'alt="'.$this->alt.'" ';
        } else {
          $this->image_tag .= 'alt="" ';
        }
        
        $this->image_tag .= ' width="'.$width.'" height="'.$height.'" ';
        
        $image_path = str_replace($this->server_path, '', $this->resized);
        
        $this->image_tag .= 'src="'.$image_path.'" />';
        
        return $this->image_tag;

    }
    
    static function usage()
{
	ob_start(); 
	?>

** You must add the server and cache dir paths to the class variables in the plugin file! **

Example:
---------

// ADD PATHS TO YOUR WEB ROOT AND CACHE FOLDER HERE
private $server_path        = '/this/is/my/website/root/folder';                    // no trailing slash
private $cache_path         = '/this/is/my/website/root/folder/and/image/cache/';   // with trailing slash

Paramaters:
----------
* image         ~ the file to resize, will parse file dirs
* maxWidth      ~ maximumm width of resized image
* maxHeight     ~ maximumm height of resized image
* forceWidth    ~ scale up if required
* forceHeight   ~ scale up if required
* cropratio     ~ eg: square is 1:1
* default       ~ the default image to use if there is no actual image
* alt           ~ alt text
* class         ~ img tag class
* id            ~ img tag id
* title         ~ img tag title
* href_only     ~ if yes just returns the path to the resized file, not the full image tag, useful for modal windows etc.
* debug         ~ defaults to no - yes for debug mode (creates error messages instead of quitting quietly)

Usage Example
----------
{exp:ed_imageresizer
    image="{my_image_field}"
    default=""/images/site/image_coming_soon.jpg"
    maxWidth="300"
    class="myimgclass"
    alt="Image description"}

	<?php
	$buffer = ob_get_contents();
	
	ob_end_clean(); 

	return $buffer;
        
    }

}
