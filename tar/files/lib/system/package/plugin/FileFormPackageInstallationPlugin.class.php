<?php

namespace wcf\system\package\plugin;

use wcf\data\fileform\FileForm;
use wcf\data\fileform\FileFormEditor;
use wcf\data\fileform\FileFormList;
use wcf\system\event\EventHandler;
use wcf\system\exception\SystemException;
use wcf\system\form\container\GroupFormElementContainer;
use wcf\system\form\element\MultipleSelectionFormElement;
use wcf\system\form\element\PasswordInputFormElement;
use wcf\system\form\element\SingleSelectionFormElement;
use wcf\system\form\element\TextInputFormElement;
use wcf\system\form\FormDocument;
use wcf\system\io\AtomicWriter;
use wcf\system\language\LanguageFactory;
use wcf\system\package\PackageArchive;
use wcf\system\package\PackageInstallationDispatcher;
use wcf\system\package\PackageInstallationFormManager;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\XML;

class FileFormPackageInstallationPlugin implements IPackageInstallationPlugin {

    /**
     * name of the form
     * @var string
     */
    protected $formName = null;

    /**
     * name of the file that will be created afterwards
     * @var string
     */
    protected $fileName = null;

    /**
     * sets how the content of the file looks like
     *   0  = declares constants
     *   1  = declares normal variables
     *   2  = returns an PHP array
     * @var integer
     */
    protected $fileType = null;

    /**
     * fields that get shown in the installation form
     * @var array
     */
    protected $fields = [];

    /**
     * active instance of PackageInstallationDispatcher
     * @var    PackageInstallationDispatcher
     */
    public $installation = null;

    /**
     * install/update instructions
     * @var    array
     */
    public $instruction = [];

    public function __construct(PackageInstallationDispatcher $installation, $instruction = []) {
        $this->installation = $installation;
        $this->instruction = $instruction;

        EventHandler::getInstance()->fireAction($this, 'construct');
    }

    /**
     * @inheritdoc
     */
    public function install() {
        EventHandler::getInstance()->fireAction($this, 'install');

        $xml = $this->getXML($this->instruction['value']);
        $xpath = $xml->xpath();

        $this->formName = ($xpath->query('/ns:form/attribute::name'))->item(0)->nodeValue;
        $this->fileName = ($xpath->query('/ns:form/ns:filename'))->item(0)->nodeValue;
        $this->fileType = ($xpath->query('/ns:form/ns:filetype'))->item(0)->nodeValue;
        $fields = $xpath->query('/ns:form/ns:fields/ns:field');

        /** @var \DOMElement $field */
        foreach ($fields as $field) {
            $data = [
                'attributes' => [],
                'elements' => [],
                'value' => ''
            ];

            $attributes = $xpath->query('attribute::*', $field);
            foreach ($attributes as $attribute) {
                $data['attributes'][$attribute->name] = $attribute->value;
            }

            $childNodes = $xpath->query('child::*', $field);
            foreach ($childNodes as $childNode) {
                $this->getElement($xpath, $data['elements'], $childNode);
            }

            if (empty($data['elements'])) {
                $data['value'] = $field->nodeValue;
            }

            $this->fields[] = $data;
        }

        if ($this->installation->getAction() == 'update') {
            $fileForm = new FileForm($this->fileName);
            if ($fileForm->fileName != null) {
                if (file_exists(WCF_DIR . $fileForm->fileName)) {
                    $file = include(WCF_DIR . $fileForm->fileName);
                    foreach ($this->fields as $key => $field) {
                        switch ($fileForm->fileType) {
                            case 0:
                                if (defined(strtoupper($field['attributes']['name']))) {
                                    $this->fields[$key]['elements']['default_value'] = constant(strtoupper($field['attributes']['name']));
                                }
                                break;
                            case 1:
                                $variable = StringUtil::firstCharToLowerCase($field['attributes']['name']);
                                $this->fields[$key]['elements']['default_value'] = $$variable;
                                break;
                            case 2:
                                $this->fields[$key]['elements']['default_value'] = $file[$field['attributes']['name']];
                                break;
                        }
                    }
                }
            }
        }

        if (!PackageInstallationFormManager::findForm($this->installation->queue, 'fileForm_' . $this->formName)) {
            $container = new GroupFormElementContainer();

            foreach ($this->fields as $field) {
                switch ($field['elements']['fieldtype']) {
                    default:
                    case 'text':
                        $formElement = new TextInputFormElement($container);
                        break;
                    case 'password':
                        $formElement = new PasswordInputFormElement($container);
                        break;
                    case 'radio':
                        $formElement = new SingleSelectionFormElement($container);
                        break;
                    case 'checkbox':
                        $formElement = new MultipleSelectionFormElement($container);
                        break;
                }

                $formElement->setName($field['attributes']['name']);
                $formElement->setLabel($this->getI18nValues($field['elements']['label'], true));
                if (isset($field['elements']['description'])) {
                    $formElement->setDescription($this->getI18nValues($field['elements']['description'], true));
                }
                if (isset($field['elements']['default_value'])) {
                    $formElement->setValue($field['elements']['default_value']);
                }

                $container->appendChild($formElement);
            }

            $document = new FormDocument('fileForm_' . $this->formName);
            $document->appendContainer($container);

            PackageInstallationFormManager::registerForm($this->installation->queue, $document);
            return $document;
        } else {
            $document = PackageInstallationFormManager::getForm($this->installation->queue, 'fileForm_' . $this->formName);
            $document->handleRequest();

            $writer = new AtomicWriter(WCF_DIR . $this->fileName);
            $writer->write("<?php\n/**\n* generated at " . gmdate('r') . "\n*/\n");
            if ($this->fileType == 2) $writer->write("return [\n");

            foreach ($this->fields as $field) {
                $value = $document->getValue($field['attributes']['name']);

                switch ($this->fileType) {
                    case 0:
                        $writer->write("if (!defined('" . strtoupper($field['attributes']['name']) . "')) define('" . strtoupper($field['attributes']['name']) . "', " . "'" . addcslashes($value, "'\\") . "'" . ");\n");
                        break;
                    case 1:
                        $writer->write("\$" . StringUtil::firstCharToLowerCase($field['attributes']['name']) . " = '" . addcslashes($value, "'\\") . "';\n");
                        break;
                    case 2:
                        $writer->write("    '" . $field['attributes']['name'] . "' => '" . addcslashes($value, "'\\") . "',\n");
                        break;
                }
            }

            if ($this->fileType == 2) $writer->write("];");
            $writer->write("\n");
            $writer->flush();
            $writer->close();

            FileFormEditor::create([
                'fileName' => $this->fileName,
                'packageID' => $this->installation->getPackageID(),
                'fileType' => $this->fileType
            ]);

            FileUtil::makeWritable(WCF_DIR . $this->fileName);
            WCF::resetZendOpcache(WCF_DIR . $this->fileName);
        }
    }

    protected function getElement(\DOMXPath $xpath, array &$elements, \DOMElement $element) {
        if ($element->tagName == 'label' || $element->tagName == 'description') {
            if (!isset($elements[$element->tagName])) $elements[$element->tagName] = [];
            $elements[$element->tagName][$element->getAttribute('language')] = $element->nodeValue;
        } else {
            $elements[$element->tagName] = $element->nodeValue;
        }
    }

    /**
     * @inheritdoc
     */
    public function uninstall() {
        EventHandler::getInstance()->fireAction($this, 'uninstall');

        $list = new FileFormList();
        $list->getConditionBuilder()->add('packageID = ?', [$this->installation->getPackageID()]);
        $list->readObjects();

        /** @var FileForm $fileform */
        foreach ($list->getObjects() as $fileform) {
            if (file_exists(WCF_DIR . $fileform->fileName)) {
                @unlink(WCF_DIR . $fileform->fileName);
            }
        }

        if ($list->count() > 0) {
            FileFormEditor::deleteAll($list->getObjectIDs());
        }
    }

    /**
     * @inheritdoc
     */
    public function hasUninstall() {
        EventHandler::getInstance()->fireAction($this, 'hasUninstall');

        $list = new FileFormList();
        $list->getConditionBuilder()->add('packageID = ?', [$this->installation->getPackageID()]);
        $list->readObjects();

        return $list->count() > 0;
    }

    /**
     * Executes the update of this plugin.
     */
    public function update() {
        EventHandler::getInstance()->fireAction($this, 'update');
        $this->install();
    }

    /**
     * Loads the xml file into a string and returns this string.
     *
     * @param    string $filename
     * @return    XML        $xml
     * @throws    SystemException
     */
    protected function getXML($filename = '') {
        if (empty($filename)) {
            $filename = $this->instruction['value'];
        }

        // Search the xml-file in the package archive.
        // Abort installation in case no file was found.
        if (($fileIndex = $this->installation->getArchive()->getTar()->getIndexByFilename($filename)) === false) {
            throw new SystemException("xml file '" . $filename . "' not found in '" . $this->installation->getArchive()->getArchive() . "'");
        }

        // Extract acpmenu file and parse XML
        $xml = new XML();
        $tmpFile = FileUtil::getTemporaryFilename('xml_');
        try {
            $this->installation->getArchive()->getTar()->extract($fileIndex, $tmpFile);
            $xml->load($tmpFile);
        } catch (\Exception $e) { // bugfix to avoid file caching problems
            try {
                $this->installation->getArchive()->getTar()->extract($fileIndex, $tmpFile);
                $xml->load($tmpFile);
            } catch (\Exception $e) {
                $this->installation->getArchive()->getTar()->extract($fileIndex, $tmpFile);
                $xml->load($tmpFile);
            }
        }

        @unlink($tmpFile);
        return $xml;
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultFilename() {
        $classParts = explode('\\', get_called_class());
        return lcfirst(str_replace('PackageInstallationPlugin', '', array_pop($classParts))) . '.xml';
    }

    /**
     * @inheritdoc
     */
    public static function isValid(PackageArchive $archive, $instruction) {
        if (!$instruction) {
            $defaultFilename = static::getDefaultFilename();
            if ($defaultFilename) {
                $instruction = $defaultFilename;
            }
        }

        if (preg_match('~\.xml$~', $instruction)) {
            // check if file actually exists
            try {
                if ($archive->getTar()->getIndexByFilename($instruction) === false) {
                    return false;
                }
            } catch (SystemException $e) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Returns i18n values by validating each value against the list of installed
     * languages, optionally returning only the best matching value.
     *
     * @param    string[] $values          list of values by language code
     * @param    boolean  $singleValueOnly true to return only the best matching value
     * @return    string[]|string    matching i18n values controller by `$singleValueOnly`
     * @since    3.0
     */
    protected function getI18nValues(array $values, $singleValueOnly = false) {
        if (empty($values)) {
            return $singleValueOnly ? '' : [];
        }

        // check for a value with an empty language code and treat it as 'en' unless 'en' exists
        if (isset($values[''])) {
            if (!isset($values['en'])) {
                $values['en'] = $values[''];
            }

            unset($values['']);
        }

        $matchingValues = [];
        foreach ($values as $languageCode => $value) {
            if (LanguageFactory::getInstance()->getLanguageByCode($languageCode) !== null) {
                $matchingValues[$languageCode] = $value;
            }
        }

        // no matching value found
        if (empty($matchingValues)) {
            if (isset($values['en'])) {
                // safest route: pick English
                $matchingValues['en'] = $values['en'];
            } else if (isset($values[''])) {
                // fallback: use the value w/o a language code
                $matchingValues[''] = $values[''];
            } else {
                // failsafe: just use the first found value in whatever language
                $matchingValues = array_splice($values, 0, 1);
            }
        }

        if ($singleValueOnly) {
            if (isset($matchingValues[LanguageFactory::getInstance()->getDefaultLanguage()->languageCode])) {
                return $matchingValues[LanguageFactory::getInstance()->getDefaultLanguage()->languageCode];
            }

            return array_shift($matchingValues);
        }

        return $matchingValues;
    }
}