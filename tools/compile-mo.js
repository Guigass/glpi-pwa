/**
 * Script Node.js para compilar arquivos .po para .mo
 * Uso: node compile-mo.js
 */

const fs = require('fs');
const path = require('path');

/**
 * Parse string do formato PO (remove aspas e escapes)
 */
function parsePoString(str) {
    str = str.trim();
    
    // Remove surrounding quotes
    if (str.length >= 2 && str[0] === '"' && str[str.length - 1] === '"') {
        str = str.slice(1, -1);
    }
    
    // Unescape
    str = str.replace(/\\n/g, '\n');
    str = str.replace(/\\t/g, '\t');
    str = str.replace(/\\"/g, '"');
    str = str.replace(/\\\\/g, '\\');
    
    return str;
}

/**
 * Parse arquivo PO e retorna objeto com traduções
 */
function parsePo(content) {
    const entries = {};
    let currentMsgid = '';
    let currentMsgstr = '';
    let inMsgid = false;
    let inMsgstr = false;

    const lines = content.split('\n');
    
    for (const rawLine of lines) {
        const line = rawLine.trim();
        
        // Skip comments or empty lines
        if (!line || line[0] === '#') {
            if (inMsgstr && currentMsgid !== '') {
                entries[currentMsgid] = currentMsgstr;
            }
            currentMsgid = '';
            currentMsgstr = '';
            inMsgid = false;
            inMsgstr = false;
            continue;
        }

        if (line.startsWith('msgid ')) {
            if (inMsgstr && currentMsgid !== '') {
                entries[currentMsgid] = currentMsgstr;
            }
            currentMsgid = parsePoString(line.slice(6));
            currentMsgstr = '';
            inMsgid = true;
            inMsgstr = false;
        } else if (line.startsWith('msgstr ')) {
            currentMsgstr = parsePoString(line.slice(7));
            inMsgid = false;
            inMsgstr = true;
        } else if (line[0] === '"') {
            // Continuation line
            const value = parsePoString(line);
            if (inMsgid) {
                currentMsgid += value;
            } else if (inMsgstr) {
                currentMsgstr += value;
            }
        }
    }

    // Don't forget the last entry
    if (currentMsgid !== '') {
        entries[currentMsgid] = currentMsgstr;
    }

    // Remove empty msgid (header)
    delete entries[''];

    return entries;
}

/**
 * Converte objeto de traduções para formato MO binário
 */
function buildMo(entries) {
    const originals = Object.keys(entries).sort();
    const translations = originals.map(k => entries[k]);
    const nstrings = originals.length;

    // MO file format (little endian):
    // - magic number (4 bytes): 0x950412de
    // - file format revision (4 bytes): 0
    // - number of strings (4 bytes)
    // - offset of table with original strings (4 bytes)
    // - offset of table with translated strings (4 bytes)
    // - size of hashing table (4 bytes): 0
    // - offset of hashing table (4 bytes): 0

    const headerSize = 28;
    const origTableOffset = headerSize;
    const transTableOffset = origTableOffset + (nstrings * 8);
    
    // Calculate string data offset
    let stringOffset = transTableOffset + (nstrings * 8);
    
    // First pass: calculate total size of original strings
    let origStringsSize = 0;
    for (const orig of originals) {
        origStringsSize += Buffer.byteLength(orig, 'utf8') + 1; // +1 for null terminator
    }
    
    const transStringsOffset = stringOffset + origStringsSize;
    
    // Build the MO file
    const parts = [];
    
    // Header
    const header = Buffer.alloc(headerSize);
    header.writeUInt32LE(0x950412de, 0);  // magic
    header.writeUInt32LE(0, 4);            // revision
    header.writeUInt32LE(nstrings, 8);     // nstrings
    header.writeUInt32LE(origTableOffset, 12);
    header.writeUInt32LE(transTableOffset, 16);
    header.writeUInt32LE(0, 20);           // hash table size
    header.writeUInt32LE(0, 24);           // hash table offset
    parts.push(header);
    
    // Original strings table
    let origOffset = stringOffset;
    const origTable = Buffer.alloc(nstrings * 8);
    for (let i = 0; i < nstrings; i++) {
        const len = Buffer.byteLength(originals[i], 'utf8');
        origTable.writeUInt32LE(len, i * 8);
        origTable.writeUInt32LE(origOffset, i * 8 + 4);
        origOffset += len + 1;
    }
    parts.push(origTable);
    
    // Translation strings table
    let transOffset = transStringsOffset;
    const transTable = Buffer.alloc(nstrings * 8);
    for (let i = 0; i < nstrings; i++) {
        const len = Buffer.byteLength(translations[i], 'utf8');
        transTable.writeUInt32LE(len, i * 8);
        transTable.writeUInt32LE(transOffset, i * 8 + 4);
        transOffset += len + 1;
    }
    parts.push(transTable);
    
    // Original strings (null-terminated)
    for (const orig of originals) {
        parts.push(Buffer.from(orig + '\0', 'utf8'));
    }
    
    // Translation strings (null-terminated)
    for (const trans of translations) {
        parts.push(Buffer.from(trans + '\0', 'utf8'));
    }
    
    return Buffer.concat(parts);
}

// Main execution
const baseDir = path.dirname(__dirname);
const localeDir = path.join(baseDir, 'locale');

try {
    // Encontrar todos os arquivos .po
    const files = fs.readdirSync(localeDir);
    const poFiles = files.filter(f => f.endsWith('.po') && f !== 'glpipwa.pot');
    
    if (poFiles.length === 0) {
        console.error(`Nenhum arquivo PO encontrado em: ${localeDir}`);
        process.exit(1);
    }
    
    let compiled = 0;
    
    for (const poFileName of poFiles) {
        // Extrair o locale do nome do arquivo (ex: pt_BR.po -> pt_BR)
        const basename = path.basename(poFileName, '.po');
        
        // Ignorar arquivos que não são de locale (ex: glpipwa.pot)
        if (basename === 'glpipwa') {
            continue;
        }
        
        const poFile = path.join(localeDir, poFileName);
        
        // Criar estrutura de diretórios: locale/{locale}/LC_MESSAGES/
        const localeSubDir = path.join(localeDir, basename, 'LC_MESSAGES');
        if (!fs.existsSync(localeSubDir)) {
            fs.mkdirSync(localeSubDir, { recursive: true });
        }
        
        // Gerar arquivo .mo na estrutura correta: locale/{locale}/LC_MESSAGES/glpipwa.mo
        const moFile = path.join(localeSubDir, 'glpipwa.mo');
        
        console.log(`Compilando: ${poFileName}...`);
        
        const poContent = fs.readFileSync(poFile, 'utf8');
        const entries = parsePo(poContent);
        const moBuffer = buildMo(entries);
        
        fs.writeFileSync(moFile, moBuffer);
        
        console.log(`  -> ${path.relative(localeDir, moFile)} (${Object.keys(entries).length} strings)`);
        compiled++;
    }
    
    console.log(`\nCompilação concluída! ${compiled} arquivo(s) compilado(s).`);
    process.exit(0);
} catch (error) {
    console.error('Erro na compilação:', error.message);
    process.exit(1);
}

