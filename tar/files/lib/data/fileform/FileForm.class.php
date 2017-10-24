<?php

namespace wcf\data\fileform;

use wcf\data\DatabaseObject;

/**
 * FileForm
 *
 *
 * @property-read   string  fileName
 * @property-read   integer packageID
 * @property-read   integer fileType
 */
class FileForm extends DatabaseObject {

    /**
     * @inheritdoc
     */
    protected static $databaseTableName = 'fileform';

    /**
     * @inheritdoc
     */
    protected static $databaseTableIndexName = 'fileName';
}