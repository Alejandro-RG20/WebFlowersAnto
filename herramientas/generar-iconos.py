#!/usr/bin/env python3
"""
Genera los iconos de Font Awesome que usa la tienda, servidos desde el propio
dominio.

Por qué
-------
La tienda usa unos 115 iconos de los ~1 600 de Font Awesome. Pedirlo entero a
cdnjs costaba en el móvil una conexión más a otro servidor, una hoja de 100 KB
y tres fuentes que suman unos 300 KB, compitiendo con la foto principal. Con
esto se sirven solo los iconos usados: una hoja de unos pocos KB y tres
fuentes de unos pocos KB cada una, desde el mismo dominio.

Qué hace
--------
1. Busca en el código (PHP, JS y CSS) todas las clases `fa-…` que se usan.
2. Saca de la hoja original de Font Awesome el carácter de cada una (los
   alias, como `fa-check-circle`, incluidos).
3. Recorta las tres fuentes a esos caracteres y escribe:
     assets/fonts/fa-solid-900.woff2
     assets/fonts/fa-regular-400.woff2
     assets/fonts/fa-brands-400.woff2
     assets/css/iconos.css

Cuándo ejecutarlo
-----------------
Cada vez que se use un icono nuevo. Si un icono no sale, es que falta
ejecutarlo.

    npm pack @fortawesome/fontawesome-free@6.5.0
    tar -xzf fortawesome-fontawesome-free-6.5.0.tgz
    pip install fonttools brotli
    python3 herramientas/generar-iconos.py package/

Los iconos no pueden salir de la base de datos ni armarse por partes en el
código (`'fa-' . $x`): este script solo ve los nombres escritos enteros.
"""

import pathlib
import re
import subprocess
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
NO_SON_ICONOS = {
    'fa', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-classic', 'fa-sharp',
    'fa-asesora-abierta',   # clave de almacenamiento del asistente, no un icono
    'fa-sel',               # prefijo de la clave de almacenamiento de la selección del panel
}
FUENTES = {
    'solid':   'fa-solid-900',
    'regular': 'fa-regular-400',
    'brands':  'fa-brands-400',
}


def usados() -> set:
    nombres = set()
    for patron in ('**/*.php', '**/*.js', '**/*.css'):
        for ruta in RAIZ.glob(patron):
            partes = ruta.relative_to(RAIZ).parts
            if partes[0] in ('tests', 'node_modules', 'herramientas') or 'dist' in partes:
                continue
            if ruta.name == 'iconos.css':
                continue
            texto = ruta.read_text(encoding='utf-8', errors='ignore')
            nombres.update(re.findall(r'\bfa-[a-z0-9]+(?:-[a-z0-9]+)*', texto))
    return {n for n in nombres if n not in NO_SON_ICONOS
            and not re.fullmatch(r'fa-\d+x?|fa-(lg|sm|xs|fw|spin)|fa-(solid|regular|brands)-\d+', n)}


def caracteres(hoja: str) -> dict:
    """{'fa-circle-check': ('f058', 'free'|'brands'), …} según la hoja original."""
    marca_marcas = hoja.index("font-family: 'Font Awesome 6 Brands';\n  font-style: normal;")
    fin_marcas = hoja.index("--fa-style-family-classic", marca_marcas)
    mapa = {}
    for m in re.finditer(r'((?:\.fa-[a-z0-9-]+::?before,?\s*)+)\{\s*content:\s*"\\([0-9a-f]+)"', hoja):
        familia = 'brands' if marca_marcas < m.start() < fin_marcas else 'free'
        for sel in re.findall(r'\.(fa-[a-z0-9-]+)::?before', m.group(1)):
            mapa.setdefault(sel, (m.group(2), familia))
    return mapa


def main() -> None:
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    paquete = pathlib.Path(sys.argv[1])
    hoja = (paquete / 'css' / 'all.css').read_text(encoding='utf-8')
    mapa = caracteres(hoja)

    pedidos = sorted(usados())
    faltan = [n for n in pedidos if n not in mapa]
    iconos = {n: mapa[n] for n in pedidos if n in mapa}
    if faltan:
        print('Aviso: no son iconos de Font Awesome 6.5 (se ignoran):', ', '.join(faltan))

    destino_fuentes = RAIZ / 'assets' / 'fonts'
    destino_fuentes.mkdir(parents=True, exist_ok=True)
    for estilo, archivo in FUENTES.items():
        familia = 'brands' if estilo == 'brands' else 'free'
        codigos = sorted({c for c, f in iconos.values() if f == familia})
        if not codigos:
            continue
        subprocess.run([
            sys.executable, '-m', 'fontTools.subset', str(paquete / 'webfonts' / f'{archivo}.ttf'),
            '--unicodes=' + ','.join(f'U+{c}' for c in codigos),
            '--flavor=woff2', '--no-hinting', '--desubroutinize', '--layout-features=*',
            '--output-file=' + str(destino_fuentes / f'{archivo}.woff2'),
        ], check=True)

    reglas = []
    for nombre, (codigo, _) in sorted(iconos.items()):
        reglas.append(f'.{nombre}::before{{content:"\\{codigo}"}}')

    css = f"""/*!
 * Font Awesome Free 6.5.0 by @fontawesome - https://fontawesome.com
 * License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License)
 * Copyright 2023 Fonticons, Inc.
 *
 * Solo los {len(iconos)} iconos que usa la tienda. Archivo generado por
 * herramientas/generar-iconos.py: no se edita a mano.
 */
@font-face{{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:900;font-display:block;src:url("../fonts/fa-solid-900.woff2") format("woff2")}}
@font-face{{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:400;font-display:block;src:url("../fonts/fa-regular-400.woff2") format("woff2")}}
@font-face{{font-family:"Font Awesome 6 Brands";font-style:normal;font-weight:400;font-display:block;src:url("../fonts/fa-brands-400.woff2") format("woff2")}}
.fa,.fas,.fa-solid,.far,.fa-regular,.fab,.fa-brands{{-moz-osx-font-smoothing:grayscale;-webkit-font-smoothing:antialiased;display:var(--fa-display,inline-block);font-style:normal;font-variant:normal;line-height:1;text-rendering:auto}}
.fa,.fas,.fa-solid,.far,.fa-regular{{font-family:"Font Awesome 6 Free"}}
.fa,.fas,.fa-solid{{font-weight:900}}
.far,.fa-regular{{font-weight:400}}
.fab,.fa-brands{{font-family:"Font Awesome 6 Brands";font-weight:400}}
""" + '\n'.join(reglas) + '\n'

    (RAIZ / 'assets' / 'css' / 'iconos.css').write_text(css, encoding='utf-8')
    print(f'{len(iconos)} iconos. Listo.')


if __name__ == '__main__':
    main()
