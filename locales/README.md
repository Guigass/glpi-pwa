# Estrutura de Traduções do Plugin GLPI PWA

Este diretório contém os arquivos de tradução do plugin seguindo o padrão oficial do GLPI.

## Estrutura Esperada

```
locales/
  glpipwa.pot                    # Template de tradução (opcional)
  pt_BR.po                       # Arquivo de tradução em português (opcional)
  pt_BR.mo                       # Arquivo compilado em português (obrigatório)
  en_GB.po                       # Arquivo de tradução em inglês (opcional)
  en_GB.mo                       # Arquivo compilado em inglês (obrigatório)
```

## Textdomain

O **textdomain** usado pelo plugin é `glpipwa`, que corresponde exatamente ao nome da pasta do plugin.

No código PHP, as strings são traduzidas usando:

```php
__('String to translate', 'glpipwa');
```

## Compilando Arquivos .mo

Os arquivos `.mo` são gerados a partir dos arquivos `.po` usando o script de compilação:

```bash
node tools/compile-mo-new.js
```

Alternativamente, se você tiver o utilitário `msgfmt` instalado (parte do pacote gettext):

```bash
msgfmt locales/pt_BR.po -o locales/pt_BR.mo
msgfmt locales/en_GB.po -o locales/en_GB.mo
```

## Adicionando um Novo Idioma

Para adicionar suporte a um novo idioma (por exemplo, `fr_FR`):

1. Crie o arquivo de tradução:

   ```bash
   cp locales/glpipwa.pot locales/fr_FR.po
   ```

2. Edite o arquivo `.po` e ajuste o cabeçalho:

   - `Language: fr_FR\n`
   - `Language-Team: French (France) <fr_FR@li.org>\n`
   - `Plural-Forms: nplurals=2; plural=(n > 1);\n` (ajuste conforme necessário para o idioma)

3. Traduza as strings (substitua os `msgstr ""` vazios pelas traduções)

4. Compile o arquivo `.mo`:

   ```bash
   node tools/compile-mo-new.js
   ```

   Ou usando msgfmt:

   ```bash
   msgfmt locales/fr_FR.po -o locales/fr_FR.mo
   ```

## Regras Importantes

- O nome dos arquivos de tradução deve seguir o padrão `{locale}.po` e `{locale}.mo` (ex: `pt_BR.po`, `pt_BR.mo`)
- Os arquivos devem estar diretamente dentro de `locales/` (não em subpastas)
- O arquivo `.pot` (template) permanece na raiz de `locales/`
- O textdomain usado no código deve ser exatamente igual ao nome da pasta do plugin (`glpipwa`)
- **IMPORTANTE**: O GLPI espera os arquivos `.mo` diretamente em `locales/`, não em subpastas `LC_MESSAGES/`

## Atualizando Traduções

Quando novas strings são adicionadas ao código:

1. Atualize o arquivo `.pot` usando ferramentas como `xgettext` ou manualmente
2. Mescle as novas strings nos arquivos `.po` existentes usando `msgmerge`:
   ```bash
   msgmerge -U locales/pt_BR.po locales/glpipwa.pot
   ```
3. Traduza as novas strings nos arquivos `.po`
4. Recompile os arquivos `.mo`:
   ```bash
   node tools/compile-mo-new.js
   ```

## Notas sobre o GLPI 11

No GLPI 11, as traduções são carregadas automaticamente pelo sistema quando:

- A pasta do plugin se chama `locales` (com "s" no final)
- Os arquivos seguem o padrão `{locale}.mo` diretamente em `locales/`
- O textdomain usado nas funções `__()` corresponde ao nome da pasta do plugin

Não é necessário chamar `Plugin::loadTranslation()` manualmente no GLPI 11.
