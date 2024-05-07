<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Service\TcaService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper used to receive crop variants configuration from
 * MelonImages croppingConfiguration.
 *
 * The configuration allows the use of MelonImages-CropVariants
 * for BE-Fields which are not represented by a DB field and
 * corresponding TCA configuration (such as flexform fields).
 *
 * The table, type, field configuration in MelonImages.yaml does not
 * have to represent a real table and can therefore be chosen freely.
 *
 * Example with ViewHelper from flux extension:
 *
 * ```
 * {namespace melon=Smichaelsen\MelonImages\ViewHelpers}
 * <flux:field.inline.fal
 *    name="image"
 *    maxItems="1"
 *    label="Image"
 *    cropVariants="{melon:cropVariants(tableName:'my_tablename', type:'my_type', fieldName:'my_fieldname')}" />
 * ```
 */
class CropVariantsViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected TcaService $tcaService;

    public function injectTcaService(TcaService $tcaService)
    {
        $this->tcaService = $tcaService;
    }

    public function initializeArguments()
    {
        $this->registerArgument('tableName', 'string', 'Tablename from cropping configuration', true);
        $this->registerArgument('type', 'string', 'Type from cropping configuration', false, '_all');
        $this->registerArgument('fieldName', 'string', 'Fieldname from cropping configuration', true);
    }

    public function render()
    {
        $tableName = $this->arguments['tableName'];
        $type = $this->arguments['type'];
        $fieldName = $this->arguments['fieldName'];

        return $this->tcaService->getCropVariants($tableName, $type, $fieldName);
    }
}
