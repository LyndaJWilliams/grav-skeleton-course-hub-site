<?php
namespace Grav\Common\GPM;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;

class Installer
{
    /** @const No error */
    const OK = 0;
    /** @const Target already exists */
    const EXISTS = 1;
    /** @const Target is a symbolic link */
    const IS_LINK = 2;
    /** @const Target doesn't exist */
    const NOT_FOUND = 4;
    /** @const Target is not a directory */
    const NOT_DIRECTORY = 8;
    /** @const Target is not a Grav instance */
    const NOT_GRAV_ROOT = 16;
    /** @const Error while trying to open the ZIP package */
    const ZIP_OPEN_ERROR = 32;
    /** @const Error while trying to extract the ZIP package */
    const ZIP_EXTRACT_ERROR = 64;

    /**
     * Destination folder on which validation checks are applied
     * @var string
     */
    protected static $target;

    /**
     * @var integer Error Code
     */
    protected static $error = 0;

    /**
     * @var string Post install message
     */
    protected static $message = '';

    /**
     * Default options for the install
     * @var array
     */
    protected static $options = [
        'overwrite'       => true,
        'ignore_symlinks' => true,
        'sophisticated'   => false,
        'theme'           => false,
        'install_path'    => '',
        'exclude_checks'  => [self::EXISTS, self::NOT_FOUND, self::IS_LINK]
    ];

    /**
     * Installs a given package to a given destination.
     *
     * @param  string $package     The local path to the ZIP package
     * @param  string $destination The local path to the Grav Instance
     * @param  array  $options     Options to use for installing. ie, ['install_path' => 'user/themes/antimatter']
     *
     * @return boolean True if everything went fine, False otherwise.
     */
    public static function install($package, $destination, $options = [])
    {
        $destination = rtrim($destination, DS);
        $options = array_merge(self::$options, $options);
        $install_path = rtrim($destination . DS . ltrim($options['install_path'], DS), DS);

        if (!self::isGravInstance($destination) || !self::isValidDestination($install_path,
                $options['exclude_checks'])
        ) {
            return false;
        }

        if (self::lastErrorCode() == self::IS_LINK && $options['ignore_symlinks'] ||
            self::lastErrorCode() == self::EXISTS && !$options['overwrite']
        ) {
            return false;
        }

        $zip = new \ZipArchive();
        $archive = $zip->open($package);
        $cache_dir = Grav::instance()['locator']->findResource('cache://', true);
        $tmp = $cache_dir . DS . 'tmp/Grav-' . uniqid();

        if ($archive !== true) {
            self::$error = self::ZIP_OPEN_ERROR;

            return false;
        }

        Folder::mkdir($tmp);

        $unzip = $zip->extractTo($tmp);

        if (!$unzip) {
            self::$error = self::ZIP_EXTRACT_ERROR;
            $zip->close();
            Folder::delete($tmp);

            return false;
        }

        $package_folder_name = $zip->getNameIndex(0);
        $installer_file_folder = $tmp . '/' . $package_folder_name;

        $is_install = true;
        $installer = self::loadInstaller($installer_file_folder, $is_install);

        if (isset($options['is_update']) && $options['is_update'] === true) {
            $method = 'preUpdate';
        } else {
            $method = 'preInstall';
        }

        if ($installer && method_exists($installer, $method)) {
            $method_result = $installer::$method();
            if ($method_result !== true) {
                self::$error = 'An error occurred';
                if (is_string($method_result)) {
                    self::$error = $method_result;
                }

                return false;
            }
        }

        if (!$options['sophisticated']) {
            if ($options['theme']) {
                self::copyInstall($zip, $install_path, $tmp);
            } else {
                self::moveInstall($zip, $install_path, $tmp);
            }
        } else {
            self::sophisticatedInstall($zip, $install_path, $tmp);
        }

        Folder::delete($tmp);
        $zip->close();

        if (isset($options['is_update']) && $options['is_update'] === true) {
            $method = 'postUpdate';
        } else {
            $method = 'postInstall';
        }

        self::$message = '';
        if ($installer && method_exists($installer, $method)) {
            self::$message = $installer::$method();
        }

        self::$error = self::OK;

        return true;

    }

    /**
     * Instantiates and returns the package installer class
     *
     * @param string $installer_file_folder The folder path that contains install.php
     * @param bool $is_install True if install, false if removal
     *
     * @return null|string
     */
    private static function loadInstaller($installer_file_folder, $is_install)
    {
        $installer = null;

        $installer_file_folder = rtrim($installer_file_folder, DS);

        $install_file = $installer_file_folder . DS . 'install.php';

        if (file_exists($install_file)) {
            require_once($install_file);
        } else {
            return null;
        }

        if ($is_install) {
            $slug = '';
            if (($pos = strpos($installer_file_folder, 'grav-plugin-')) !== false) {
                $slug = substr($installer_file_folder, $pos + strlen('grav-plugin-'));
            } elseif (($pos = strpos($installer_file_folder, 'grav-theme-')) !== false) {
                $slug = substr($installer_file_folder, $pos + strlen('grav-theme-'));
            }
        } else {
            $path_elements = explode('/', $installer_file_folder);
            $slug = end($path_elements);
        }

        if (!$slug) {
            return null;
        }

        $class_name = ucfirst($slug) . 'Install';

        if (class_exists($class_name)) {
            return $class_name;
        }

        return $installer;
    }

    /**
     * @param \ZipArchive $zip
     * @param             $install_path
     * @param             $tmp
     *
     * @return bool
     */
    public static function moveInstall(\ZipArchive $zip, $install_path, $tmp)
    {
        $container = $zip->getNameIndex(0);
        if (file_exists($install_path)) {
            Folder::delete($install_path);
        }

        Folder::move($tmp . DS . $container, $install_path);

        return true;
    }

    /**
     * @param \ZipArchive $zip
     * @param             $install_path
     * @param             $tmp
     *
     * @return bool
     */
    public static function copyInstall(\ZipArchive $zip, $install_path, $tmp)
    {
        $firstDir = $zip->getNameIndex(0);
        if (empty($firstDir)) {
            throw new \RuntimeException("Directory $firstDir is missing");
        } else {
            $tmp = realpath($tmp . DS . $firstDir);
            Folder::rcopy($tmp, $install_path);
        }

        return true;
    }

    /**
     * @param \ZipArchive $zip
     * @param             $install_path
     * @param             $tmp
     *
     * @return bool
     */
    public static function sophisticatedInstall(\ZipArchive $zip, $install_path, $tmp)
    {
        for ($i = 0, $l = $zip->numFiles; $i < $l; $i++) {
            $filename = $zip->getNameIndex($i);
            $fileinfo = pathinfo($filename);
            $depth = count(explode(DS, rtrim($filename, '/')));

            if ($depth > 2) {
                continue;
            }

            $path = $install_path . DS . $fileinfo['basename'];

            if (is_link($path)) {
                continue;
            } else {
                if (is_dir($path)) {
                    Folder::delete($path);
                    Folder::move($tmp . DS . $filename, $path);

                    if ($fileinfo['basename'] == 'bin') {
                        foreach (glob($path . DS . '*') as $file) {
                            @chmod($file, 0755);
                        }
                    }
                } else {
                    @unlink($path);
                    @copy($tmp . DS . $filename, $path);
                }
            }
        }

        return true;
    }

    /**
     * Uninstalls one or more given package
     *
     * @param  string $path    The slug of the package(s)
     * @param  array  $options Options to use for uninstalling
     *
     * @return boolean True if everything went fine, False otherwise.
     */
    public static function uninstall($path, $options = [])
    {
        $options = array_merge(self::$options, $options);
        if (!self::isValidDestination($path, $options['exclude_checks'])
        ) {
            return false;
        }

        $installer_file_folder = $path;
        $is_install = false;
        $installer = self::loadInstaller($installer_file_folder, $is_install);

        if ($installer && method_exists($installer, 'preUninstall')) {
            $method_result = $installer::preUninstall();
            if ($method_result !== true) {
                self::$error = 'An error occurred';
                if (is_string($method_result)) {
                    self::$error = $method_result;
                }

                return false;
            }
        }

        $result = Folder::delete($path);

        self::$message = '';
        if ($result && $installer && method_exists($installer, 'postUninstall')) {
            self::$message = $installer::postUninstall();
        }

        return $result;
    }

    /**
     * Runs a set of checks on the destination and sets the Error if any
     *
     * @param  string $destination The directory to run validations at
     * @param  array  $exclude     An array of constants to exclude from the validation
     *
     * @return boolean True if validation passed. False otherwise
     */
    public static function isValidDestination($destination, $exclude = [])
    {
        self::$error = 0;
        self::$target = $destination;

        if (is_link($destination)) {
            self::$error = self::IS_LINK;
        } elseif (file_exists($destination)) {
            self::$error = self::EXISTS;
        } elseif (!file_exists($destination)) {
            self::$error = self::NOT_FOUND;
        } elseif (!is_dir($destination)) {
            self::$error = self::NOT_DIRECTORY;
        }

        if (count($exclude) && in_array(self::$error, $exclude)) {
            return true;
        }

        return !(self::$error);
    }

    /**
     * Validates if the given path is a Grav Instance
     *
     * @param  string $target The local path to the Grav Instance
     *
     * @return boolean True if is a Grav Instance. False otherwise
     */
    public static function isGravInstance($target)
    {
        self::$error = 0;
        self::$target = $target;

        if (
            !file_exists($target . DS . 'index.php') ||
            !file_exists($target . DS . 'bin') ||
            !file_exists($target . DS . 'user') ||
            !file_exists($target . DS . 'system' . DS . 'config' . DS . 'system.yaml')
        ) {
            self::$error = self::NOT_GRAV_ROOT;
        }

        return !self::$error;
    }

    /**
     * Returns the last message added by the installer
     * @return string The message
     */
    public static function getMessage()
    {
        return self::$message;
    }

    /**
     * Returns the last error occurred in a string message format
     * @return string The message of the last error
     */
    public static function lastErrorMsg()
    {
        if (is_string(self::$error)) {
            return self::$error;
        }

        switch (self::$error) {
            case 0:
                $msg = 'No Error';
                break;

            case self::EXISTS:
                $msg = 'The target path "' . self::$target . '" already exists';
                break;

            case self::IS_LINK:
                $msg = 'The target path "' . self::$target . '" is a symbolic link';
                break;

            case self::NOT_FOUND:
                $msg = 'The target path "' . self::$target . '" does not appear to exist';
                break;

            case self::NOT_DIRECTORY:
                $msg = 'The target path "' . self::$target . '" does not appear to be a folder';
                break;

            case self::NOT_GRAV_ROOT:
                $msg = 'The target path "' . self::$target . '" does not appear to be a Grav instance';
                break;

            case self::ZIP_OPEN_ERROR:
                $msg = 'Unable to open the package file';
                break;

            case self::ZIP_EXTRACT_ERROR:
                $msg = 'An error occurred while extracting the package';
                break;

            default:
                $msg = 'Unknown Error';
                break;
        }

        return $msg;
    }

    /**
     * Returns the last error code of the occurred error
     * @return integer The code of the last error
     */
    public static function lastErrorCode()
    {
        return self::$error;
    }

    /**
     * Allows to manually set an error
     *
     * @param int|string $error the Error code
     */

    public static function setError($error)
    {
        self::$error = $error;
    }
}
