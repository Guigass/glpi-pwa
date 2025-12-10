<?php
/**
 * Script para compilar arquivos .po para .mo
 * Uso: php compile-mo.php
 */

/**
 * Converte arquivo PO para MO
 * Baseado no formato GNU gettext MO
 */
function poToMo($poFile, $moFile) {
    $content = file_get_contents($poFile);
    if ($content === false) {
        echo "Erro ao ler arquivo: $poFile\n";
        return false;
    }

    // Parse PO file
    $entries = [];
    $currentMsgid = '';
    $currentMsgstr = '';
    $inMsgid = false;
    $inMsgstr = false;

    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments
        if (empty($line) || $line[0] === '#') {
            if ($inMsgstr && $currentMsgid !== '') {
                $entries[$currentMsgid] = $currentMsgstr;
            }
            $currentMsgid = '';
            $currentMsgstr = '';
            $inMsgid = false;
            $inMsgstr = false;
            continue;
        }

        if (strpos($line, 'msgid ') === 0) {
            if ($inMsgstr && $currentMsgid !== '') {
                $entries[$currentMsgid] = $currentMsgstr;
            }
            $currentMsgid = parsePoString(substr($line, 6));
            $currentMsgstr = '';
            $inMsgid = true;
            $inMsgstr = false;
        } elseif (strpos($line, 'msgstr ') === 0) {
            $currentMsgstr = parsePoString(substr($line, 7));
            $inMsgid = false;
            $inMsgstr = true;
        } elseif ($line[0] === '"') {
            // Continuation line
            $value = parsePoString($line);
            if ($inMsgid) {
                $currentMsgid .= $value;
            } elseif ($inMsgstr) {
                $currentMsgstr .= $value;
            }
        }
    }

    // Don't forget the last entry
    if ($currentMsgid !== '') {
        $entries[$currentMsgid] = $currentMsgstr;
    }

    // Remove empty msgid (header)
    unset($entries['']);

    // Sort entries by msgid
    ksort($entries);

    // Build MO file
    $nstrings = count($entries);
    
    // MO file format:
    // - magic number (4 bytes)
    // - file format revision (4 bytes)
    // - number of strings (4 bytes)
    // - offset of table with original strings (4 bytes)
    // - offset of table with translated strings (4 bytes)
    // - size of hashing table (4 bytes)
    // - offset of hashing table (4 bytes)
    // - original strings table
    // - translated strings table
    // - original strings
    // - translated strings

    $magic = 0x950412de; // Little endian magic number
    $revision = 0;
    $origTableOffset = 28; // Header is 28 bytes
    $transTableOffset = $origTableOffset + ($nstrings * 8);
    $hashTableSize = 0;
    $hashTableOffset = 0;

    // Calculate string offsets
    $stringOffset = $transTableOffset + ($nstrings * 8);
    
    $originals = array_keys($entries);
    $translations = array_values($entries);
    
    $origTable = '';
    $transTable = '';
    $origStrings = '';
    $transStrings = '';
    
    $origOffset = $stringOffset;
    $transOffset = $stringOffset;
    
    // First pass: calculate total size of original strings
    foreach ($originals as $orig) {
        $transOffset += strlen($orig) + 1;
    }
    
    // Build tables
    foreach ($originals as $i => $orig) {
        $trans = $translations[$i];
        
        // Original string table entry: length (4 bytes) + offset (4 bytes)
        $origTable .= pack('VV', strlen($orig), $origOffset);
        $origOffset += strlen($orig) + 1;
        
        // Translation string table entry
        $transTable .= pack('VV', strlen($trans), $transOffset);
        $transOffset += strlen($trans) + 1;
        
        // String data (null-terminated)
        $origStrings .= $orig . "\0";
        $transStrings .= $trans . "\0";
    }

    // Build header
    $header = pack('V', $magic);
    $header .= pack('V', $revision);
    $header .= pack('V', $nstrings);
    $header .= pack('V', $origTableOffset);
    $header .= pack('V', $transTableOffset);
    $header .= pack('V', $hashTableSize);
    $header .= pack('V', $hashTableOffset);

    // Write MO file
    $moContent = $header . $origTable . $transTable . $origStrings . $transStrings;
    
    $result = file_put_contents($moFile, $moContent);
    if ($result === false) {
        echo "Erro ao escrever arquivo: $moFile\n";
        return false;
    }

    echo "Arquivo compilado com sucesso: $moFile ($nstrings strings)\n";
    return true;
}

/**
 * Parse string do formato PO (remove aspas e escapes)
 */
function parsePoString($str) {
    $str = trim($str);
    
    // Remove surrounding quotes
    if (strlen($str) >= 2 && $str[0] === '"' && $str[strlen($str) - 1] === '"') {
        $str = substr($str, 1, -1);
    }
    
    // Unescape
    $str = str_replace('\\n', "\n", $str);
    $str = str_replace('\\t', "\t", $str);
    $str = str_replace('\\"', '"', $str);
    $str = str_replace('\\\\', '\\', $str);
    
    return $str;
}

// Main execution
$baseDir = dirname(__DIR__);
$localeDir = $baseDir . '/locale';

// Encontrar todos os arquivos .po
$poFiles = glob($localeDir . '/*.po');

if (empty($poFiles)) {
    echo "Nenhum arquivo PO encontrado em: $localeDir\n";
    exit(1);
}

$success = true;
$compiled = 0;

foreach ($poFiles as $poFile) {
    $moFile = preg_replace('/\.po$/', '.mo', $poFile);
    
    echo "Compilando: " . basename($poFile) . "...\n";
    
    if (poToMo($poFile, $moFile)) {
        $compiled++;
    } else {
        $success = false;
    }
}

if ($success) {
    echo "\nCompilação concluída! $compiled arquivo(s) compilado(s).\n";
    exit(0);
} else {
    echo "\nAlguns arquivos falharam na compilação.\n";
    exit(1);
}

