<?php
/**
 * Behavior for file uploads
 *
 * Example Usage:
 *
 * @example
 *   var $actsAs = array('FileUpload.FileUpload');
 *
 * @example
 *   var $actsAs = array(
 *     'FileUpload.FileUpload' => array(
 *       'uploadDir'    => WEB_ROOT . DS . 'files',
 *       'fields'       => array('name' => 'file_name', 'type' => 'file_type', 'size' => 'file_size'),
 *       'allowedTypes' => array('pdf' => array('application/pdf')),
 *       'required'    => false,
 *       'unique' => false //filenames will overwrite existing files of the same name. (default true)
 *       'fileNameFunction' => 'sha1' //execute the Sha1 function on a filename before saving it (default false)
 *     )
 *    )
 *
 *
 * @note: Please review the plugins/file_upload/config/file_upload_settings.php file for details on each setting.
 * @version: since 6.1.0
 * @author: Nick Baker
 * @link: http://www.webtechnick.com
 */
App::import('Vendor', 'FileUpload.uploader');
require_once dirname(__FILE__) . DS . '..' . DS . '..' . DS . 'config' . DS . 'file_upload_settings.php';
class FileUploadBehavior extends ModelBehavior
{

    /**
     * Uploader is the uploader instance of class Uploader. This will handle the actual file saving.
     */
    /**
     * @param $Model
     * @param $key
     * @param $value
     */
    public $Uploader = [];

    /**
     * @param $Model
     * @param $key
     * @param $value
     */
    public function setFileUploadOption(&$Model, $key, $value)
    {
        $this->options[$Model->alias][$key] = $value;
        $this->Uploader[$Model->alias]->setOption($key, $value);
    }

    /**
     * Setup the behavior
     */
    public function setUp(Model $Model, $options = [])
    {

        $FileUploadSettings = new FileUploadSettings();
        if (!is_array($options)) {
            $options = [];
        }
        $this->options[$Model->alias] = array_merge($FileUploadSettings->defaults, $options);
        // read from Configure::
        $uploadDir = Configure::read('GLABELS_ROOT');

        $uploader_settings = $this->options[$Model->alias];
        $uploader_settings['uploadDir'] = $this->options[$Model->alias]['forceWebroot'] ?
        WWW_ROOT . $uploadDir : $uploadDir;
        $uploader_settings['fileModel'] = $Model->alias;

        $this->Uploader[$Model->alias] = new Uploader($uploader_settings);
    }

    /**
     * check multiple keys exist in an array
     */
    public function array_keys_exists(array $keys, array $arr)
    {
        return !array_diff_key(array_flip($keys), $arr);
    }

    /**
     * check if it's an upload field i.e. is array and has keys as in
     * $options->cakePHPUploadKeys
     */
    public function checkIsUploadField($arrayToCheck, $keysToCheckFor)
    {
        return is_array($arrayToCheck) &&
        $this->array_keys_exists($keysToCheckFor, $arrayToCheck);
    }
    /**
     * beforeSave if a file is found, upload it, and then save the filename according to the settings
     *
     */
    public function beforeSave(Model $Model, $options = [])
    {
        foreach ($this->options[$Model->alias]['uploadFormFields'] as $formField) {

            $keysToCheckFor = $this->options[$Model->alias]['uploadFieldKeys'];

            $file = !empty($Model->data[$Model->alias][$formField]) ?
            $Model->data[$Model->alias][$formField] : false;

            if ($this->checkIsUploadField($file, $keysToCheckFor)) {

                $this->Uploader[$Model->alias]->file = $file;

                if ($this->Uploader[$Model->alias]->hasUpload()) {

                    $fileName = $this->Uploader[$Model->alias]->processFile();

                    if ($Model->id) {
                        $this->fileTemplateName = $Model->findById($Model->id);
                        $previousFileName = $this->fileTemplateName["PrintTemplate"][$formField];
                        if ($previousFileName !== $fileName) {
                            $this->Uploader[$Model->alias]->removeFile($previousFileName);
                        }
                    }

                    if ($fileName) {
                        $Model->data[$Model->alias][$formField] = $fileName;
                        $Model->data[$Model->alias][$formField . '_' . $this->options[$Model->alias]['fields']['size']] = $file['size'];
                        $Model->data[$Model->alias][$formField . '_' . $this->options[$Model->alias]['fields']['type']] = $file['type'];
                    } else {
                        return false; // we couldn't save the file, return false
                    }

                    // not sure what this is for now so commenting
                    //unset($Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']]);
                } else {

                    unset($Model->data[$Model->alias][$formField]);
                }
            };
        }
        return $Model->beforeSave();
    }

    /**
     * Updates validation errors if there was an error uploading the file.
     * presents the user the errors.
     */
    public function beforeValidate(Model $Model, $options = [])
    {
        foreach ($this->options[$Model->alias]['uploadFormFields'] as $uploadFormField) {
            if (isset($Model->data[$Model->alias][$uploadFormField])) {
                $file = $Model->data[$Model->alias][$uploadFormField];
                $this->Uploader[$Model->alias]->file = $file;
                if ($this->Uploader[$Model->alias]->hasUpload()) {
                    if ($this->Uploader[$Model->alias]->checkFile() && $this->Uploader[$Model->alias]->checkType() && $this->Uploader[$Model->alias]->checkSize()) {
                        $Model->beforeValidate();
                    } else {
                        $Model->validationErrors[$uploadFormField] = $this->Uploader[$Model->alias]->showErrors();
                    }
                } else {
                    if (isset($this->options[$Model->alias]['required']) && $this->options[$Model->alias]['required']) {
                        $Model->validationErrors[$this->options[$Model->alias][$uploadFormField]] = 'Select file to upload';
                    }
                }
            } elseif (isset($this->options[$Model->alias]['required']) && $this->options[$Model->alias]['required']) {
                $Model->validationErrors[$this->options[$Model->alias][$uploadFormField]] = 'No File';
            }
        }
        return $Model->beforeValidate();
    }

    /**
     * Automatically remove the uploaded file.
     */
    public function beforeDelete(Model $Model, $cascade = true)
    {
        $Model->recursive = -1;
        $data = $Model->read();

        foreach ($this->options[$Model->alias]['uploadFormFields'] as $field) {
            $this->Uploader[$Model->alias]->removeFile($data[$Model->alias][$field]);
        }

        return $Model->beforeDelete($cascade);
    }

}
