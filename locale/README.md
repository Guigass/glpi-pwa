# Estrutura de Traduções do Plugin GLPI PWA

Este diretório contém os arquivos de tradução do plugin seguindo o padrão do GLPI.

## Estrutura Esperada

```
locale/
  glpipwa.pot                    # Template de tradução (opcional)
  en_GB/
    LC_MESSAGES/
      glpipwa.po                 # Arquivo de tradução em inglês (opcional)
      glpipwa.mo                 # Arquivo compilado em inglês (obrigatório)
  pt_BR/
    LC_MESSAGES/
      glpipwa.po                 # Arquivo de tradução em português (opcional)
      glpipwa.mo                 # Arquivo compilado em português (obrigatório)
```

## Textdomain

O **textdomain** usado pelo plugin é `glpipwa`, que corresponde exatamente ao nome da pasta do plugin.

No código PHP, as traduções são carregadas usando:
```php
Plugin::loadTranslation('glpipwa');
```

E as strings são traduzidas usando:
```php
__('String to translate', 'glpipwa');
```

## Compilando Arquivos .mo

Os arquivos `.mo` são gerados a partir dos arquivos `.po` usando o script de compilação:

```bash
node tools/compile-mo-fixed.js
```

Alternativamente, se você tiver o utilitário `msgfmt` instalado (parte do pacote gettext):

```bash
msgfmt locale/pt_BR/LC_MESSAGES/glpipwa.po -o locale/pt_BR/LC_MESSAGES/glpipwa.mo
msgfmt locale/en_GB/LC_MESSAGES/glpipwa.po -o locale/en_GB/LC_MESSAGES/glpipwa.mo
```

## Adicionando um Novo Idioma

Para adicionar suporte a um novo idioma (por exemplo, `fr_FR`):

1. Crie a estrutura de diretórios:
   ```bash
   mkdir -p locale/fr_FR/LC_MESSAGES
   ```

2. Copie o template e ajuste o cabeçalho:
   ```bash
   cp locale/glpipwa.pot locale/fr_FR/LC_MESSAGES/glpipwa.po
   ```

3. Edite o arquivo `.po` e ajuste o cabeçalho:
   - `Language: fr_FR\n`
   - `Language-Team: French (France) <fr_FR@li.org>\n`
   - `Plural-Forms: nplurals=2; plural=(n > 1);\n` (ajuste conforme necessário para o idioma)

4. Traduza as strings (substitua os `msgstr ""` vazios pelas traduções)

5. Compile o arquivo `.mo`:
   ```bash
   msgfmt locale/fr_FR/LC_MESSAGES/glpipwa.po -o locale/fr_FR/LC_MESSAGES/glpipwa.mo
   ```

## Regras Importantes

- O nome dos arquivos de tradução deve ser sempre `glpipwa.po` e `glpipwa.mo` (igual ao textdomain)
- Os arquivos devem estar dentro de `locale/<lang>/LC_MESSAGES/`
- Nenhum arquivo `.po` ou `.mo` deve ficar solto diretamente dentro de `locale/<lang>/` (fora de `LC_MESSAGES`)
- O arquivo `.pot` (template) permanece na raiz de `locale/`
- O textdomain usado no código deve ser exatamente igual ao nome da pasta do plugin

## Atualizando Traduções

Quando novas strings são adicionadas ao código:

1. Atualize o arquivo `.pot` usando ferramentas como `xgettext` ou manualmente
2. Mescle as novas strings nos arquivos `.po` existentes usando `msgmerge`:
   ```bash
   msgmerge -U locale/pt_BR/LC_MESSAGES/glpipwa.po locale/glpipwa.pot
   ```
3. Traduza as novas strings nos arquivos `.po`
4. Recompile os arquivos `.mo`

