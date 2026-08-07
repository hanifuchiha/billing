<?php
/**
 * SimpleXLSX Parser - Simplified version for basic XLSX reading
 * Based on SimpleXLSX library concept
 */
class SimpleXLSX {
    private static $error;
    private $data = [];
    
    public static function parse($filename) {
        $instance = new self();
        return $instance->parseFile($filename) ? $instance : false;
    }
    
    public static function parseError() {
        return self::$error;
    }
    
    public function rows() {
        return $this->data;
    }
    
    private function parseFile($filename) {
        try {
            // Check if file exists
            if (!file_exists($filename)) {
                self::$error = "File tidak ditemukan";
                return false;
            }
            
            // Check if it's a valid zip file (XLSX is a zip file)
            $zip = new ZipArchive();
            $res = $zip->open($filename);
            
            if ($res !== TRUE) {
                self::$error = "File bukan format XLSX yang valid";
                return false;
            }
            
            // Read shared strings
            $sharedStrings = [];
            if (($sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $sharedStringsXML = simplexml_load_string($sharedStringsXML);
                if ($sharedStringsXML) {
                    foreach ($sharedStringsXML->si as $si) {
                        $sharedStrings[] = (string) $si->t;
                    }
                }
            }
            
            // Read worksheet data
            $worksheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($worksheetXML === false) {
                self::$error = "Tidak dapat membaca worksheet";
                $zip->close();
                return false;
            }
            
            $xml = simplexml_load_string($worksheetXML);
            if (!$xml) {
                self::$error = "Format XML worksheet tidak valid";
                $zip->close();
                return false;
            }
            
            // Parse cells
            $rows = [];
            $currentRow = 1;
            $maxCols = 0;
            
            if (isset($xml->sheetData->row)) {
                foreach ($xml->sheetData->row as $row) {
                    $rowIndex = (int) $row['r'];
                    
                    // Fill empty rows if needed
                    while ($currentRow < $rowIndex) {
                        $rows[$currentRow] = [];
                        $currentRow++;
                    }
                    
                    $rowData = [];
                    $maxCol = 0;
                    
                    if (isset($row->c)) {
                        foreach ($row->c as $cell) {
                            $cellRef = (string) $cell['r'];
                            $colIndex = $this->columnIndexFromString(preg_replace('/[0-9]+/', '', $cellRef));
                            
                            if ($colIndex > $maxCol) {
                                $maxCol = $colIndex;
                            }
                            
                            $value = '';
                            if (isset($cell->v)) {
                                $cellValue = (string) $cell->v;
                                
                                // Check cell type
                                if (isset($cell['t']) && $cell['t'] == 's') {
                                    // Shared string
                                    $index = (int) $cellValue;
                                    $value = isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
                                } else {
                                    $value = $cellValue;
                                }
                            }
                            
                            $rowData[$colIndex] = $value;
                        }
                    }
                    
                    // Fill missing columns with empty strings
                    for ($i = 1; $i <= $maxCol; $i++) {
                        if (!isset($rowData[$i])) {
                            $rowData[$i] = '';
                        }
                    }
                    
                    // Sort by column index and convert to indexed array
                    ksort($rowData);
                    $rows[$currentRow] = array_values($rowData);
                    
                    if ($maxCol > $maxCols) {
                        $maxCols = $maxCol;
                    }
                    
                    $currentRow++;
                }
            }
            
            // Normalize all rows to have the same number of columns
            foreach ($rows as &$row) {
                while (count($row) < $maxCols) {
                    $row[] = '';
                }
            }
            
            $this->data = array_values($rows); // Re-index from 0
            $zip->close();
            
            return true;
            
        } catch (Exception $e) {
            self::$error = "Error parsing file: " . $e->getMessage();
            return false;
        }
    }
    
    private function columnIndexFromString($columnString) {
        $columnIndex = 0;
        $length = strlen($columnString);
        
        for ($i = 0; $i < $length; $i++) {
            $columnIndex = $columnIndex * 26 + (ord($columnString[$i]) - ord('A') + 1);
        }
        
        return $columnIndex;
    }
}
?>