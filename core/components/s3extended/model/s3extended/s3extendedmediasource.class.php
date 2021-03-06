<?php
//////////////////////////////////////////////////////////////
///  S3 Extended by Robin Rijkeboer <robin@qaraqter.nl>     //
//   available at https://github.com/Qaraqter/s3extended   ///
//////////////////////////////////////////////////////////////
///                                                         //
// s3extendedmediasource.class.php - media source type      //
//                                                         ///
//////////////////////////////////////////////////////////////

/**
 * @package s3extended
 * @subpackage sources
 */
require_once MODX_CORE_PATH . 'model/modx/sources/modmediasource.class.php';
ob_start();
if (!include_once(dirname(__FILE__).'/s3extended.functions.php')) {
    ob_end_flush();
    die('failed to include_once("'.realpath(dirname(__FILE__).'/s3extended.functions.php').'")');
}
ob_end_clean();
/**
 * Implements an Amazon S3-based media source, allowing basic manipulation, uploading and URL-retrieval of resources
 * in a specified S3 bucket.
 * 
 * @package modx
 * @subpackage sources
 */
class S3ExtendedMediaSource extends modMediaSource implements modMediaSourceInterface {
    /** @var AmazonS3 $driver */
    public $driver;
    /** @var string $bucket */
    public $bucket;
    /** @var class $functions */
    public $functions;

    /**
     * Override the constructor to always force S3 sources to not be streams.
     *
     * {@inheritDoc}
     *
     * @param xPDO $xpdo
     */
    public function __construct(xPDO & $xpdo) {
        parent::__construct($xpdo);
        $this->set('is_stream',false);
        $this->xpdo->lexicon->load('s3extended:default');
    }

    /**
     * Initializes S3 media class, getting the S3 driver and loading the bucket
     * @return boolean
     */
    public function initialize() {
        parent::initialize();
        $properties = $this->getPropertyList();
        if (!defined('AWS_KEY')) {
            define('AWS_KEY',$this->xpdo->getOption('key',$properties,''));
            define('AWS_SECRET_KEY',$this->xpdo->getOption('secret_key',$properties,''));
            /* (Not needed at this time)
            define('AWS_ACCOUNT_ID',$modx->getOption('aws.account_id',$config,''));
            define('AWS_CANONICAL_ID',$modx->getOption('aws.canonical_id',$config,''));
            define('AWS_CANONICAL_NAME',$modx->getOption('aws.canonical_name',$config,''));
            define('AWS_MFA_SERIAL',$modx->getOption('aws.mfa_serial',$config,''));
            define('AWS_CLOUDFRONT_KEYPAIR_ID',$modx->getOption('aws.cloudfront_keypair_id',$config,''));
            define('AWS_CLOUDFRONT_PRIVATE_KEY_PEM',$modx->getOption('aws.cloudfront_private_key_pem',$config,''));
            define('AWS_ENABLE_EXTENSIONS', 'false');*/
        }
        include_once $this->xpdo->getOption('core_path',null,MODX_CORE_PATH).'model/aws/sdk.class.php';

        $this->getDriver();
        $this->setBucket($this->xpdo->getOption('bucket',$properties,''));
        $this->functions = new s3extended_functions($this);
        return true;
    }

    /**
     * Get the name of this source type
     * @return string
     */
    public function getTypeName() {
        return $this->xpdo->lexicon('s3extended.name');
    }

    /**
     * Get the description of this source type
     * @return string
     */
    public function getTypeDescription() {
        return $this->xpdo->lexicon('s3extended.description');
    }

    /**
     * Get the code of this source type
     * @return string
     */
    public function getCodeName() {
        return $this->xpdo->lexicon('s3extended.code');
    }
    /**
     * Gets the AmazonS3 class instance
     * @return AmazonS3
     */
    public function getDriver() {
        if (empty($this->driver)) {
            try {
                $this->driver = new AmazonS3();
            } catch (Exception $e) {
                $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modAws] Could not load AmazonS3 class: '.$e->getMessage());
            }
        }
        return $this->driver;
    }

    /**
     * Set the bucket for the connection to S3
     * @param string $bucket
     * @return void
     */
    public function setBucket($bucket) {
        $this->bucket = $bucket;
    }

    /**
     * Get a list of objects from within a bucket
     * @param string $dir
     * @return array
     */
    public function getS3ObjectList($dir) {
        $c['delimiter'] = '/';
        if (!empty($dir) && $dir != '/') { $c['prefix'] = $dir; }

        $list = array();
        $cps = $this->driver->list_objects($this->bucket,$c);
        foreach ($cps->body->CommonPrefixes as $prefix) {
            if (!empty($prefix->Prefix) && $prefix->Prefix != $dir && $prefix->Prefix != '/') {
                $list[] = (string)$prefix->Prefix;
            }
        }
        $response = $this->driver->get_object_list($this->bucket,$c);
        foreach ($response as $file) {
            $list[] = $file;
        }
        return $list;
    }

    /**
     * Get the ID of the edit file action
     *
     * @return boolean|int
     */
    public function getEditActionId() {
        $editAction = false;
        /** @var modAction $act */
        $act = $this->xpdo->getObject('modAction',array('controller' => 'system/file/edit'));
        if ($act) { $editAction = $act->get('id'); }
        return $editAction;
    }

    /**
     * @param string $path
     * @return array
     */
    public function getContainerList($path) {
        $properties = $this->getPropertyList();
        $list = $this->getS3ObjectList($path);
        $editAction = $this->getEditActionId();
        $hideCache = $this->getOption('hideS3Cache', $this->properties, 'Yes');
        $cacheFolder = $this->getOption('s3CacheFolder',$this->properties,'_cache') . "/";

        $useMultiByte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');

        $directories = array();
        $files = array();
        foreach ($list as $idx => $currentPath) {
            if ($currentPath == $path) continue;
            if ($hideCache == "Yes" && $currentPath == $cacheFolder) continue;
            $fileName = basename($currentPath);
            if($fileName == ".cached") continue;
            $isDir = substr(strrev($currentPath),0,1) === '/';

            $extension = pathinfo($fileName,PATHINFO_EXTENSION);
            $extension = $useMultiByte ? mb_strtolower($extension,$encoding) : strtolower($extension);

            $relativePath = $currentPath == '/' ? $currentPath : str_replace($path,'',$currentPath);
            $slashCount = substr_count($relativePath,'/');
            if (($slashCount > 1 && $isDir) || ($slashCount > 0 && !$isDir)) {
                continue;
            }
            if ($isDir) {
                $directories[$currentPath] = array(
                    'id' => $currentPath,
                    'text' => $fileName,
                    'cls' => 'icon-'.$extension,
                    'type' => 'dir',
                    'leaf' => false,
                    'path' => $currentPath,
                    'pathRelative' => $currentPath,
                    'perms' => '',
                );
                $directories[$currentPath]['menu'] = array('items' => $this->getListContextMenu($currentPath,$isDir,$directories[$currentPath]));
            } else {
                $page = '?a='.$editAction.'&file='.$currentPath.'&wctx='.$this->ctx->get('key').'&source='.$this->get('id');

                $isBinary = $this->isBinary(rtrim($properties['url'],'/').'/'.$currentPath);

                $cls = array();
                $cls[] = 'icon-'.$extension;
                if($isBinary) {
                    $cls[] = 'icon-lock';
                }

                if ($this->hasPermission('file_remove')) $cls[] = 'premove';
                if ($this->hasPermission('file_update')) $cls[] = 'pupdate';

                $files[$currentPath] = array(
                    'id' => $currentPath,
                    'text' => $fileName,
                    'cls' => implode(' ', $cls),
                    'type' => 'file',
                    'leaf' => true,
                    'path' => $currentPath,
                    'page' => $isBinary ? null : $page,
                    'pathRelative' => $currentPath,
                    'directory' => $currentPath,
                    'url' => rtrim($properties['url'],'/').'/'.$currentPath,
                    'file' => $currentPath,
                );
                $files[$currentPath]['menu'] = array('items' => $this->getListContextMenu($currentPath,$isDir,$files[$currentPath]));
            }
        }

        $ls = array();
        /* now sort files/directories */
        ksort($directories);
        foreach ($directories as $dir) {
            $ls[] = $dir;
        }
        ksort($files);
        foreach ($files as $file) {
            $ls[] = $file;
        }

        return $ls;
    }

    /**
     * Get the context menu for when viewing the source as a tree
     * 
     * @param string $file
     * @param boolean $isDir
     * @param array $fileArray
     * @return array
     */
    public function getListContextMenu($file,$isDir,array $fileArray) {
        $menu = array();
        if (!$isDir) { /* files */
            if ($this->hasPermission('file_update')) {
                if ($fileArray['page'] != null) {
                    $menu[] = array(
                        'text' => $this->xpdo->lexicon('file_edit'),
                        'handler' => 'this.editFile',
                    );
                    $menu[] = array(
                        'text' => $this->xpdo->lexicon('quick_update_file'),
                        'handler' => 'this.quickUpdateFile',
                    );
                }
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameFile',
                );
            }
            if ($this->hasPermission('file_view')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_download'),
                    'handler' => 'this.downloadFile',
                );
            }
            if ($this->hasPermission('file_remove')) {
                if (!empty($menu)) $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_remove'),
                    'handler' => 'this.removeFile',
                );
            }
        } else { /* directories */
            if ($this->hasPermission('directory_create')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_create_here'),
                    'handler' => 'this.createDirectory',
                );
            }
            $menu[] = array(
                'text' => $this->xpdo->lexicon('directory_refresh'),
                'handler' => 'this.refreshActiveNode',
            );
            if ($this->hasPermission('file_upload')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('upload_files'),
                    'handler' => 'this.uploadFiles',
                );
            }
            if ($this->hasPermission('file_create')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_create'),
                    'handler' => 'this.createFile',
                );
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('quick_create_file'),
                    'handler' => 'this.quickCreateFile',
                );
            }
            if ($this->hasPermission('directory_remove')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_remove'),
                    'handler' => 'this.removeDirectory',
                );
            }
        }
        return $menu;
    }

    /**
     * Get all files in the directory and prepare thumbnail views
     * 
     * @param string $path
     * @return array
     */
    public function getObjectsInContainer($path) {
        $list = $this->getS3ObjectList($path);
        $properties = $this->getPropertyList();

        $modAuth = $this->xpdo->user->getUserToken($this->xpdo->context->get('key'));

        /* get default settings */
        $use_multibyte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');
        $bucketUrl = rtrim($properties['url'],'/').'/';
        $allowedFileTypes = $this->getOption('allowedFileTypes',$this->properties,'');
        $allowedFileTypes = !empty($allowedFileTypes) && is_string($allowedFileTypes) ? explode(',',$allowedFileTypes) : $allowedFileTypes;
        $imageExtensions = $this->getOption('imageExtensions',$this->properties,'jpg,jpeg,png,gif');
        $imageExtensions = explode(',',$imageExtensions);
        $thumbnailType = $this->getOption('thumbnailType',$this->properties,'png');
        $thumbnailQuality = $this->getOption('thumbnailQuality',$this->properties,90);
        $skipFiles = $this->getOption('skipFiles',$this->properties,'.svn,.git,_notes,.DS_Store');
        $skipFiles = explode(',',$skipFiles);
        $skipFiles[] = '.';
        $skipFiles[] = '..';

        /* iterate */
        $files = array();
        foreach ($list as $object) {
            $objectUrl = $bucketUrl.trim($object,'/');
            $baseName = basename($object);
            $isDir = substr(strrev($object),0,1) == '/' ? true : false;
            if (in_array($object,$skipFiles)){
            continue;
            }

            if (!$isDir) {
                $fileArray = array(
                    'id' => $object,
                    'name' => $baseName,
                    'url' => $objectUrl,
                    'relativeUrl' => $objectUrl,
                    'fullRelativeUrl' => $objectUrl,
                    'pathname' => $objectUrl,
                    'size' => 0,
                    'leaf' => true,
                    'menu' => array(
                        array('text' => $this->xpdo->lexicon('file_remove'),'handler' => 'this.removeFile'),
                    ),
                );

                $fileArray['ext'] = pathinfo($baseName,PATHINFO_EXTENSION);
                $fileArray['ext'] = $use_multibyte ? mb_strtolower($fileArray['ext'],$encoding) : strtolower($fileArray['ext']);
                $fileArray['cls'] = 'icon-'.$fileArray['ext'];

                if (!empty($allowedFileTypes) && !in_array($fileArray['ext'],$allowedFileTypes)) continue;

                /* get thumbnail */
                if (in_array($fileArray['ext'],$imageExtensions)) {
                    $imageWidth = $this->ctx->getOption('filemanager_image_width', 400);
                    $imageHeight = $this->ctx->getOption('filemanager_image_height', 300);
                    $thumbHeight = $this->ctx->getOption('filemanager_thumb_height', 60);
                    $thumbWidth = $this->ctx->getOption('filemanager_thumb_width', 80);

                    $size = @getimagesize($objectUrl);
                    if (is_array($size)) {
                        $imageWidth = $size[0] > 800 ? 800 : $size[0];
                        $imageHeight = $size[1] > 600 ? 600 : $size[1];
                    }

                    /* ensure max h/w */
                    if ($thumbWidth > $imageWidth) $thumbWidth = $imageWidth;
                    if ($thumbHeight > $imageHeight) $thumbHeight = $imageHeight;
                    $images = $this->functions->generateFileManagerThumbs($objectUrl);
                    $fileArray['thumb'] = $images[0];
                    $fileArray['image'] = $images[1];
                } else {
                    $fileArray['thumb'] = $this->ctx->getOption('manager_url', MODX_MANAGER_URL).'templates/default/images/restyle/nopreview.jpg';
                    $fileArray['thumbWidth'] = $this->ctx->getOption('filemanager_thumb_width', 80);
                    $fileArray['thumbHeight'] = $this->ctx->getOption('filemanager_thumb_height', 60);
                }
                $files[] = $fileArray;
            }
        }
        return $files;
    }

    /**
     * Create a Container
     *
     * @param string $name
     * @param string $parentContainer
     * @return boolean
     */
    public function createContainer($name,$parentContainer) {
        $newPath = $parentContainer.rtrim($name,'/').'/';
        /* check to see if folder already exists */
        if ($this->driver->if_object_exists($this->bucket,$newPath)) {
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_ae').': '.$newPath);
            return false;
        }

        /* create empty file that acts as folder */
        $created = $this->driver->create_object($this->bucket,$newPath,array(
            'body' => '',
            'acl' => AmazonS3::ACL_PUBLIC,
            'length' => 0,
        ));

        if (!$created) {
            $this->addError('name',$this->xpdo->lexicon('file_folder_err_create').$newPath);
            return false;
        }

        $this->xpdo->logManagerAction('directory_create','',$newPath);
        return true;
    }

    /**
     * Remove an empty folder from s3
     *
     * @param $path
     * @return boolean
     */
    public function removeContainer($path) {
        if (!$this->driver->if_object_exists($this->bucket, $path)) {
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': '.$path);
            return false;
        }

        /* remove file from s3 */
        $deleted = $this->driver->delete_object($this->bucket,$path);

        /* log manager action */
        $this->xpdo->logManagerAction('directory_remove','',$path);

        return !empty($deleted);
    }

    /**
     * Create a file
     *
     * @param string $objectPath
     * @param string $name
     * @param string $content
     * @return boolean|string
     */
    public function createObject($objectPath,$name,$content) {
        /* check to see if file already exists */
        if ($this->driver->if_object_exists($this->bucket,$objectPath.$name)) {
            $this->addError('file',sprintf($this->xpdo->lexicon('file_err_ae'),$objectPath.$name));
            return false;
        }

        /* create empty file that acts as folder */
        $created = $this->driver->create_object($this->bucket,$objectPath.$name,array(
                'body' => $content,
                'acl' => AmazonS3::ACL_PUBLIC,
                'length' => 0,
           ));

        if (!$created) {
            $this->addError('name',$this->xpdo->lexicon('file_err_create').$objectPath.$name);
            return false;
        }

        $this->xpdo->logManagerAction('file_create','',$objectPath.$name);
        return true;
    }

    /**
     * Update the contents of a file
     *
     * @param string $objectPath
     * @param string $content
     * @return boolean|string
     */
    public function updateObject($objectPath,$content) {
        /* create empty file that acts as folder */
        $created = $this->driver->create_object($this->bucket,$objectPath,array(
                 'body' => $content,
                 'acl' => AmazonS3::ACL_PUBLIC,
                 'length' => 0,
            ));

        if (!$created) {
            $this->addError('name',$this->xpdo->lexicon('file_err_create').$objectPath);
            return false;
        }

        $this->xpdo->logManagerAction('file_create','',$objectPath);
        return true;
    }


    /**
     * Delete a file from S3
     * 
     * @param string $objectPath
     * @return boolean
     */
    public function removeObject($objectPath) {
        if (!$this->driver->if_object_exists($this->bucket,$objectPath)) {
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': '.$objectPath);
            return false;
        }

        /* remove file from s3 */
        $deleted = $this->driver->delete_object($this->bucket,$objectPath);

        /* log manager action */
        $this->xpdo->logManagerAction('file_remove','',$objectPath);

        return !empty($deleted);
    }

    /**
     * Rename/move a file
     * 
     * @param string $oldPath
     * @param string $newName
     * @return bool
     */
    public function renameObject($oldPath,$newName) {
        if (!$this->driver->if_object_exists($this->bucket,$oldPath)) {
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': '.$oldPath);
            return false;
        }
        $dir = dirname($oldPath);
        $newPath = ($dir != '.' ? $dir.'/' : '').$newName;

        $copied = $this->driver->copy_object(array(
            'bucket' => $this->bucket,
            'filename' => $oldPath,
        ),array(
            'bucket' => $this->bucket,
            'filename' => $newPath,
        ),array(
            'acl' => AmazonS3::ACL_PUBLIC,
        ));
        if (!$copied) {
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_rename').': '.$oldPath);
            return false;
        }

        $this->driver->delete_object($this->bucket,$oldPath);

        $this->xpdo->logManagerAction('file_rename','',$oldPath);
        return true;
    }

    /**
     * Upload files to S3
     * 
     * @param string $container
     * @param array $objects
     * @return bool
     */
    public function uploadObjectsToContainer($container,array $objects = array()) {
        if ($container == '/' || $container == '.') $container = '';
        $downSize = $this->getOption('downSize', $this->properties, 'No');
        $downSizeWidth = $this->getOption('downSizeWidth', $this->properties, '300');
        $downSizeHeight = $this->getOption('downSizeHeight', $this->properties, '300');
        $downSizeQuality = $this->getOption('downSizeQuality', $this->properties, '90');
        $sanitizeFiles = $this->getOption('sanitizeFiles', $this->properties, 'Yes');
        $keepRatio = $this->getOption('keepRatio', $this->properties, 'Yes');

        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = explode(',', $imageExtensions);
        $allowedFileTypes = explode(',', $this->xpdo->getOption('upload_files', null, ''));
        $allowedFileTypes = array_merge(explode(',', $this->xpdo->getOption('upload_images')), explode(',', $this->xpdo->getOption('upload_media')), explode(',', $this->xpdo->getOption('upload_flash')), $allowedFileTypes);
        $allowedFileTypes = array_unique($allowedFileTypes);
        $maxFileSize = $this->xpdo->getOption('upload_maxsize',null,1048576);
        $this->xpdo->lexicon->load('s3extended:errors');
        /* loop through each file and upload */
        foreach ($objects as $file) {
            if ($file['error'] != 0) continue;
            if (empty($file['name'])) continue;
            $ext = @pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);

            if (empty($ext) || !in_array($ext,$allowedFileTypes)) {
                $this->addError('path',$this->xpdo->lexicon('file_err_ext_not_allowed', array(
                    'ext' => $ext,
                )));
                continue;
            }
            $size = @filesize($file['tmp_name']);

            if ($size > $maxFileSize) {
                $this->addError('path',$this->xpdo->lexicon('file_err_too_large', array(
                    'size' => $size,
                    'allowed' => $maxFileSize,
                )));
                continue;
            }

            if ($sanitizeFiles == "Yes") {
                $newPath =  strtolower(str_replace(" ", "-", $container . $file['name']));
            }else{
                $newPath =  $container . $file['name'];
            }

            if ($downSize == "Yes" && in_array($ext, $imageExtensions)) {
                $assetsPath = MODX_ASSETS_PATH . "components/s3extended/tmp";
                $filename = $assetsPath . "/" . uniqid() . "." . $ext;
                $cacheName = $assetsPath . "/" . uniqid() . "." . $ext;

                if (!file_exists($assetsPath)) {
                    $oldmask = umask(0);
                    mkdir($assetsPath, 0777, true);
                    umask($oldmask);
                }

                copy($file['tmp_name'], $filename);
                
                // Get new sizes
                list($width, $height) = getimagesize($filename);

                if ($keepRatio == "Yes") {
                    if ($width >= $height) {
                        $onePercent = $width / 100;
                        $downSizePercentage = floatval(($downSizeWidth / $onePercent)) / 100;
                        $downSizeHeight = $height * $downSizePercentage;
                    } else {
                        $onePercent = $height / 100;
                        $downSizePercentage = floatval(($downSizeHeight / $onePercent)) / 100;
                        $downSizeWidth = $width * $downSizePercentage;
                    }
                }
                // Load
                $thumb = imagecreatetruecolor($downSizeWidth, $downSizeHeight);
                switch ($ext) {
                    case "gif":
                        $source = imagecreatefromgif($filename);
                        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $downSizeWidth, $downSizeHeight, $width, $height);
                        imagegif($thumb, $cacheName, $downSizeQuality);
                        break;
                    case "jpeg":
                        $source = imagecreatefromjpeg($filename);
                        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $downSizeWidth, $downSizeHeight, $width, $height);
                        imagejpeg($thumb, $cacheName, $downSizeQuality);
                        break;
                    case "jpg":
                        $source = imagecreatefromjpeg($filename);
                        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $downSizeWidth, $downSizeHeight, $width, $height);
                        imagejpeg($thumb, $cacheName, $downSizeQuality);
                        break;
                    case "png":
                        $source = imagecreatefrompng($filename);
                        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $downSizeWidth, $downSizeHeight, $width, $height);
                        imagepng($thumb, $cacheName, $downSizeQuality);
                        break;
                    default:
                        $this->xpdo->log(modX::LOG_LEVEL_ERROR, "[" . $this->getTypeName() . "] " . $this->xpdo->lexicon('s3extended.notImplemented') . " File: " . $file['name']);
                        break;
                }
                $file['tmp_name'] = $cacheName;
                $size = @filesize($file['tmp_name']);
            }

            $contentType = $this->getContentType($ext);
            $uploaded = $this->driver->create_object($this->bucket, $newPath, array(
                'fileUpload' => $file['tmp_name'],
                'acl' => AmazonS3::ACL_PUBLIC,
                'length' => $size,
                'contentType' => $contentType,
            ));

            if (!$uploaded) {
                $this->addError('path', $this->xpdo->lexicon('file_err_upload'));
            }else{
                if ($downSize == "Yes" && in_array($ext, $imageExtensions)) {
                    if (file_exists($filename)) {
                        unlink($filename);
                    }
                    if (file_exists($cacheName)) {
                        unlink($cacheName);
                    }
                }
            }
        }

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerUpload', array(
            'files' => &$objects,
            'directory' => $container,
            'source' => &$this,
        ));

        $this->xpdo->logManagerAction('file_upload', '', $container);

        return !$this->hasErrors();
    }

    /**
     * Get the content type of the file based on extension
     * @param string $ext
     * @return string
     */
    public function getContentType($ext) {
        $contentType = 'application/octet-stream';
        $mimeTypes = array(
            '323' => 'text/h323',
            'acx' => 'application/internet-property-stream',
            'ai' => 'application/postscript',
            'aif' => 'audio/x-aiff',
            'aifc' => 'audio/x-aiff',
            'aiff' => 'audio/x-aiff',
            'asf' => 'video/x-ms-asf',
            'asr' => 'video/x-ms-asf',
            'asx' => 'video/x-ms-asf',
            'au' => 'audio/basic',
            'avi' => 'video/x-msvideo',
            'axs' => 'application/olescript',
            'bas' => 'text/plain',
            'bcpio' => 'application/x-bcpio',
            'bin' => 'application/octet-stream',
            'bmp' => 'image/bmp',
            'c' => 'text/plain',
            'cat' => 'application/vnd.ms-pkiseccat',
            'cdf' => 'application/x-cdf',
            'cer' => 'application/x-x509-ca-cert',
            'class' => 'application/octet-stream',
            'clp' => 'application/x-msclip',
            'cmx' => 'image/x-cmx',
            'cod' => 'image/cis-cod',
            'cpio' => 'application/x-cpio',
            'crd' => 'application/x-mscardfile',
            'crl' => 'application/pkix-crl',
            'crt' => 'application/x-x509-ca-cert',
            'csh' => 'application/x-csh',
            'css' => 'text/css',
            'dcr' => 'application/x-director',
            'der' => 'application/x-x509-ca-cert',
            'dir' => 'application/x-director',
            'dll' => 'application/x-msdownload',
            'dms' => 'application/octet-stream',
            'doc' => 'application/msword',
            'dot' => 'application/msword',
            'dvi' => 'application/x-dvi',
            'dxr' => 'application/x-director',
            'eps' => 'application/postscript',
            'etx' => 'text/x-setext',
            'evy' => 'application/envoy',
            'exe' => 'application/octet-stream',
            'fif' => 'application/fractals',
            'flr' => 'x-world/x-vrml',
            'gif' => 'image/gif',
            'gtar' => 'application/x-gtar',
            'gz' => 'application/x-gzip',
            'h' => 'text/plain',
            'hdf' => 'application/x-hdf',
            'hlp' => 'application/winhlp',
            'hqx' => 'application/mac-binhex40',
            'hta' => 'application/hta',
            'htc' => 'text/x-component',
            'htm' => 'text/html',
            'html' => 'text/html',
            'htt' => 'text/webviewhtml',
            'ico' => 'image/x-icon',
            'ief' => 'image/ief',
            'iii' => 'application/x-iphone',
            'ins' => 'application/x-internet-signup',
            'isp' => 'application/x-internet-signup',
            'jfif' => 'image/pipeg',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'js' => 'application/x-javascript',
            'latex' => 'application/x-latex',
            'lha' => 'application/octet-stream',
            'lsf' => 'video/x-la-asf',
            'lsx' => 'video/x-la-asf',
            'lzh' => 'application/octet-stream',
            'm13' => 'application/x-msmediaview',
            'm14' => 'application/x-msmediaview',
            'm3u' => 'audio/x-mpegurl',
            'man' => 'application/x-troff-man',
            'mdb' => 'application/x-msaccess',
            'me' => 'application/x-troff-me',
            'mht' => 'message/rfc822',
            'mhtml' => 'message/rfc822',
            'mid' => 'audio/mid',
            'mny' => 'application/x-msmoney',
            'mov' => 'video/quicktime',
            'movie' => 'video/x-sgi-movie',
            'mp2' => 'video/mpeg',
            'mp3' => 'audio/mpeg',
            'mpa' => 'video/mpeg',
            'mpe' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'mpg' => 'video/mpeg',
            'mpp' => 'application/vnd.ms-project',
            'mpv2' => 'video/mpeg',
            'ms' => 'application/x-troff-ms',
            'mvb' => 'application/x-msmediaview',
            'nws' => 'message/rfc822',
            'oda' => 'application/oda',
            'p10' => 'application/pkcs10',
            'p12' => 'application/x-pkcs12',
            'p7b' => 'application/x-pkcs7-certificates',
            'p7c' => 'application/x-pkcs7-mime',
            'p7m' => 'application/x-pkcs7-mime',
            'p7r' => 'application/x-pkcs7-certreqresp',
            'p7s' => 'application/x-pkcs7-signature',
            'pbm' => 'image/x-portable-bitmap',
            'pdf' => 'application/pdf',
            'pfx' => 'application/x-pkcs12',
            'pgm' => 'image/x-portable-graymap',
            'pko' => 'application/ynd.ms-pkipko',
            'pma' => 'application/x-perfmon',
            'pmc' => 'application/x-perfmon',
            'pml' => 'application/x-perfmon',
            'pmr' => 'application/x-perfmon',
            'pmw' => 'application/x-perfmon',
            'pnm' => 'image/x-portable-anymap',
            'pot' => 'application/vnd.ms-powerpoint',
            'ppm' => 'image/x-portable-pixmap',
            'pps' => 'application/vnd.ms-powerpoint',
            'ppt' => 'application/vnd.ms-powerpoint',
            'prf' => 'application/pics-rules',
            'ps' => 'application/postscript',
            'pub' => 'application/x-mspublisher',
            'qt' => 'video/quicktime',
            'ra' => 'audio/x-pn-realaudio',
            'ram' => 'audio/x-pn-realaudio',
            'ras' => 'image/x-cmu-raster',
            'rgb' => 'image/x-rgb',
            'rmi' => 'audio/mid',
            'roff' => 'application/x-troff',
            'rtf' => 'application/rtf',
            'rtx' => 'text/richtext',
            'scd' => 'application/x-msschedule',
            'sct' => 'text/scriptlet',
            'setpay' => 'application/set-payment-initiation',
            'setreg' => 'application/set-registration-initiation',
            'sh' => 'application/x-sh',
            'shar' => 'application/x-shar',
            'sit' => 'application/x-stuffit',
            'snd' => 'audio/basic',
            'spc' => 'application/x-pkcs7-certificates',
            'spl' => 'application/futuresplash',
            'src' => 'application/x-wais-source',
            'sst' => 'application/vnd.ms-pkicertstore',
            'stl' => 'application/vnd.ms-pkistl',
            'stm' => 'text/html',
            'svg' => 'image/svg+xml',
            'sv4cpio' => 'application/x-sv4cpio',
            'sv4crc' => 'application/x-sv4crc',
            't' => 'application/x-troff',
            'tar' => 'application/x-tar',
            'tcl' => 'application/x-tcl',
            'tex' => 'application/x-tex',
            'texi' => 'application/x-texinfo',
            'texinfo' => 'application/x-texinfo',
            'tgz' => 'application/x-compressed',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'tr' => 'application/x-troff',
            'trm' => 'application/x-msterminal',
            'tsv' => 'text/tab-separated-values',
            'txt' => 'text/plain',
            'uls' => 'text/iuls',
            'ustar' => 'application/x-ustar',
            'vcf' => 'text/x-vcard',
            'vrml' => 'x-world/x-vrml',
            'wav' => 'audio/x-wav',
            'wcm' => 'application/vnd.ms-works',
            'wdb' => 'application/vnd.ms-works',
            'wks' => 'application/vnd.ms-works',
            'wmf' => 'application/x-msmetafile',
            'wps' => 'application/vnd.ms-works',
            'wri' => 'application/x-mswrite',
            'wrl' => 'x-world/x-vrml',
            'wrz' => 'x-world/x-vrml',
            'xaf' => 'x-world/x-vrml',
            'xbm' => 'image/x-xbitmap',
            'xla' => 'application/vnd.ms-excel',
            'xlc' => 'application/vnd.ms-excel',
            'xlm' => 'application/vnd.ms-excel',
            'xls' => 'application/vnd.ms-excel',
            'xlt' => 'application/vnd.ms-excel',
            'xlw' => 'application/vnd.ms-excel',
            'xof' => 'x-world/x-vrml',
            'xpm' => 'image/x-xpixmap',
            'xwd' => 'image/x-xwindowdump',
            'z' => 'application/x-compress',
            'zip' => 'application/zip'
        );
        if (in_array(strtolower($ext),$mimeTypes)) {
            $contentType = $mimeTypes[$ext];
        } else {
            $contentType = 'octet/application-stream';
        }
        return $contentType;
    }

    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from
     * @param string $to The location to move to
     * @param string $point
     * @return boolean
     */
    public function moveObject($from,$to,$point = 'append') {
        $this->xpdo->lexicon->load('source');
        $success = false;

        if (substr(strrev($from),0,1) == '/') {
            $this->xpdo->error->message = $this->xpdo->lexicon('s3_no_move_folder',array(
                'from' => $from
            ));
            return $success;
        }

        if (!$this->driver->if_object_exists($this->bucket,$from)) {
            $this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns').': '.$from;
            return $success;
        }

        if ($to != '/') {
            if (!$this->driver->if_object_exists($this->bucket,$to)) {
                $this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns').': '.$to;
                return $success;
            }
            $toPath = rtrim($to,'/').'/'.basename($from);
        } else {
            $toPath = basename($from);
        }
        
        $response = $this->driver->copy_object(array(
            'bucket' => $this->bucket,
            'filename' => $from,
        ),array(
            'bucket' => $this->bucket,
            'filename' => $toPath,
        ),array(
            'acl' => AmazonS3::ACL_PUBLIC,
        ));
        $success = $response->isOK();

        if ($success) {
            $deleteResponse = $this->driver->delete_object($this->bucket, $from);
            $success = $deleteResponse->isOK();
        } else {
            $this->xpdo->error->message = $this->xpdo->lexicon('file_folder_err_rename').': '.$to.' -> '.$from;
        }

        return $success;
    }

    /**
     * @return array
     */
    public function getDefaultProperties() {
        return array(
            'url' => array(
                'name' => 'url',
                'desc' => 's3extended.url_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 'http://mysite.s3.amazonaws.com/',
                'lexicon' => 's3extended:properties',
            ),
            'bucket' => array(
                'name' => 'bucket',
                'desc' => 's3extended.bucket_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 's3extended:properties',
            ),
            'key' => array(
                'name' => 'key',
                'desc' => 's3extended.key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 's3extended:properties',
            ),
            'secret_key' => array(
                'name' => 'secret_key',
                'desc' => 's3extended.secret_key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 's3extended:properties',
            ),
            'imageExtensions' => array(
                'name' => 'imageExtensions',
                'desc' => 's3extended.imageExtensions_desc',
                'type' => 'textfield',
                'value' => 'jpg,jpeg,png,gif',
                'lexicon' => 's3extended:properties',
            ),
            'thumbnailQuality' => array(
                'name' => 'thumbnailQuality',
                'desc' => 's3extended.thumbnailQuality_desc',
                'type' => 'textfield',
                'value' => 90,
                'lexicon' => 's3extended:properties',
            ),
            'skipFiles' => array(
                'name' => 'skipFiles',
                'desc' => 's3extended.skipFiles_desc',
                'type' => 'textfield',
                'value' => '.svn,.git,_notes,nbproject,.idea,.DS_Store',
                'lexicon' => 's3extended:properties',
            ),
            'sanitizeFiles' => array(
                'name' => 'sanitizeFiles',
                'desc' => 's3extended.sanitizeFiles_desc',
                'type' => 'yesno',
                'value' => 'Yes',
                'lexicon' => 's3extended:properties',
            ),
            'cacheToS3' => array(
                'name' => 'cacheToS3',
                'desc' => 's3extended.cacheToS3_desc',
                'type' => 'yesno',
                'value' => 'Yes',
                'lexicon' => 's3extended:properties',
            ),
            's3CacheFolder' => array(
                'name' => 's3CacheFolder',
                'desc' => 's3extended.s3CacheFolder_desc',
                'type' => 'textfield',
                'value' => '_cache',
                'lexicon' => 's3extended:properties',
            ),
            'hideS3Cache' => array(
                'name' => 'hideS3Cache',
                'desc' => 's3extended.hideS3Cache_desc',
                'type' => 'yesno',
                'value' => 'Yes',
                'lexicon' => 's3extended:properties',
            ),
            'downSize' => array(
                'name' => 'downSize',
                'desc' => 's3extended.downSize_desc',
                'type' => 'yesno',
                'value' => 'No',
                'lexicon' => 's3extended:properties',
            ),
            'keepRatio' => array(
                'name' => 'keepRatio',
                'desc' => 's3extended.keepRatio_desc',
                'type' => 'yesno',
                'value' => 'Yes',
                'lexicon' => 's3extended:properties',
            ),
            'downSizeWidth' => array(
                'name' => 'downSizeWidth',
                'desc' => 's3extended.downSizeWidth_desc',
                'type' => 'textfield',
                'value' => 300,
                'lexicon' => 's3extended:properties',
            ),
            'downSizeHeight' => array(
                'name' => 'downSizeHeight',
                'desc' => 's3extended.downSizeHeight_desc',
                'type' => 'textfield',
                'value' => 300,
                'lexicon' => 's3extended:properties',
            ),
            'downSizeQuality' => array(
                'name' => 'downSizeQuality',
                'desc' => 's3extended.downSizeQuality_desc',
                'type' => 'textfield',
                'value' => 90,
                'lexicon' => 's3extended:properties',
            ),
            'debugMode' => array(
                'name' => 'debugMode',
                'desc' => 's3extended.debugMode_desc',
                'type' => 'yesno',
                'value' => 'No',
                'lexicon' => 's3extended:properties',
            ),
        );
    }

    /**
     * Prepare a src parameter to be rendered with phpThumb
     * 
     * @param string $src
     * @return string
     */
    public function prepareSrcForThumb($src) {
        $properties = $this->getPropertyList();
        if (strpos($src,$properties['url']) === false) {
            $src = $properties['url'].ltrim($src,'/');
        }
        return $src;
    }

    /**
     * Get the base URL for this source. Only applicable to sources that are streams.
     *
     * @param string $object An optional object to find the base url of
     * @return string
     */
    public function getBaseUrl($object = '') {
        $properties = $this->getPropertyList();
        return $properties['url'];
    }

    /**
     * Get the absolute URL for a specified object. Only applicable to sources that are streams.
     *
     * @param string $object
     * @return string
     */
    public function getObjectUrl($object = '') {
        $properties = $this->getPropertyList();
        return $properties['url'].$object;
    }

    public function getObjectFileSize($filename) {
        return $this->driver->get_object_filesize($this->bucket, $filename);
    }

    /**
     * Tells if a file is a binary file or not.
     *
     * @param string $file
     * @return boolean True if a binary file.
     */
    public function isBinary($file, $isContent = false) {
        $file = str_replace(' ','%20', $file);
        if(!$isContent) {
            $file = file_get_contents($file, null, null, null, 512);
        }

        $content = str_replace(array("\n", "\r", "\t"), '', $file);
        return ctype_print($content) ? false : true;
    }

    /**
     * Get the contents of a specified file
     *
     * @param string $objectPath
     * @return array
     */
    public function getObjectContents($objectPath) {
        $properties = $this->getPropertyList();
        $objectUrl = str_replace(' ','%20', $properties['url'].$objectPath);
        $contents = @file_get_contents($objectUrl);
        $imageExtensions = $this->getOption('imageExtensions',$this->properties,'jpg,jpeg,png,gif');
        $imageExtensions = explode(',',$imageExtensions);
        $fileExtension = pathinfo($objectPath,PATHINFO_EXTENSION);
        return array(
            'name' => $objectPath,
            'basename' => basename($objectPath),
            'path' => $objectPath,
            'size' => $this->getObjectFileSize($objectPath),
            'last_accessed' => '',
            'last_modified' => '',
            'content' => $contents,
            'image' => in_array($fileExtension,$imageExtensions) ? true : false,
            'is_writable' => !$this->isBinary($contents, true),
            'is_readable' => true,
        );
    }
}