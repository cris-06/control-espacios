{{--
    Formato 10 «Actividades o eventos» del CUV, versión imprimible.

    Reproduce el Word institucional (FORMATO 10 - JORNADA ACADÉMICA CONTA Y SISTEMAS LISTO.docx):
    - Papel carta vertical. El membrete es la imagen de página completa del encabezado del Word
      (logotipos, franjas rojas y marca de agua), sin modificar.
    - Márgenes del Word: 5 cm arriba, 2.5 cm abajo. Se reservan en cada hoja con el encabezado y
      el pie de la tabla «marco», que el navegador repite al imprimir.
    - Tabla de 17.5 cm centrada en la hoja, columnas de 4.99, 4.25, 2.00, 1.75 y 4.51 cm; bordes
      de 0.5 pt #999999, sombreado #D9D9D9 y márgenes de celda de 0.19 cm, como en el Word.
    Solo muestra datos; no tiene formularios ni escribe nada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Formato 10 {{ $folioInstitucional ?? $folio }}</title>
    <style>
        @page {
            size: letter portrait;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #d4d4d4;
            color: #000;
            /* Calibri es la letra del Word; Carlito tiene sus mismas medidas y es libre. */
            font-family: Calibri, Carlito, 'Liberation Sans', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---------- Barra de pantalla (no se imprime) ---------- */
        .barra {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 16px;
            padding: 10px 16px;
            background: #fff;
            border-bottom: 3px solid #bf0a30;
            font-family: system-ui, 'Segoe UI', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.4;
        }

        .barra p {
            margin: 0;
            flex: 1 1 320px;
            color: #333;
        }

        .barra a,
        .barra button {
            font: inherit;
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid #bf0a30;
            cursor: pointer;
            text-decoration: none;
        }

        .barra button {
            background: #bf0a30;
            color: #fff;
            font-weight: 600;
        }

        .barra a {
            background: #fff;
            color: #bf0a30;
        }

        /* ---------- Hoja ---------- */
        .hoja {
            position: relative;
            width: 21.59cm;
            min-height: 27.94cm;
            margin: 16px auto;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .25);
            overflow: hidden;
        }

        .membrete {
            position: absolute;
            top: 0;
            left: 0;
            width: 21.59cm;
            height: 27.94cm;
            z-index: 0;
        }

        /* Pie del Word: el nombre del formato alineado al margen derecho. */
        .pie {
            position: absolute;
            left: 3cm;
            right: 3cm;
            bottom: 1.25cm;
            z-index: 1;
            text-align: right;
        }

        .pie .formato {
            font-family: Arial, 'Liberation Sans', sans-serif;
            font-size: 10pt;
            white-space: nowrap;
        }

        table.marco {
            position: relative;
            z-index: 1;
            width: 100%;
            border-collapse: collapse;
        }

        table.marco > thead > tr > td {
            height: 5cm;
            padding: 0;
        }

        table.marco > tfoot > tr > td {
            height: 2.5cm;
            padding: 0;
        }

        table.marco > tbody > tr > td {
            padding: 0;
        }

        /* La tabla del Word queda centrada en la hoja y la primera hoja empieza 0.64 cm más arriba
           (posición vertical de la tabla en el Word). */
        .contenido {
            width: 17.5cm;
            margin: -0.64cm 0 0 2.045cm;
        }

        /* ---------- Tabla del formato ---------- */
        table.f10 {
            width: 17.5cm;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .f10 td,
        .f10 th {
            border: 0.5pt solid #999999;
            /* Como el Word: 0.19 cm a los lados y nada arriba ni abajo. */
            padding: 0 0.19cm;
            vertical-align: top;
            text-align: left;
            font-weight: normal;
            overflow-wrap: anywhere;
        }

        .f10 tr {
            break-inside: avoid;
        }

        .f10 tr.largo {
            break-inside: auto;
        }

        .gris {
            background: #d9d9d9;
        }

        .etiqueta {
            font-size: 11pt;
            font-weight: bold !important;
        }

        .titulo {
            font-size: 12pt;
            font-weight: bold !important;
            text-align: center !important;
        }

        .seccion {
            font-size: 11pt;
            font-weight: bold !important;
            text-align: center !important;
        }

        .t10 {
            font-size: 10pt;
        }

        .t9 {
            font-size: 9pt;
        }

        .centro {
            text-align: center !important;
        }

        .justificado {
            text-align: justify !important;
        }

        .negrita,
        .f10 .negrita {
            font-weight: bold;
        }

        .mayusculas {
            text-transform: uppercase;
        }

        .parrafo {
            white-space: pre-line;
        }

        .nota {
            display: block;
            margin-top: 0.05cm;
            font-size: 7.5pt;
            color: #333;
        }

        .vacio {
            color: #555;
            font-style: italic;
        }

        /* Las tres fechas a 10 pt (el Word usa 11) para que cada una quepa en un renglón. */
        .encabezado-datos {
            font-size: 10pt;
        }

        .encabezado-datos p {
            margin: 0;
        }

        /* El valor de cada fecha no se parte: si no cabe, pasa completo al renglón siguiente. */
        .encabezado-datos .valor {
            font-weight: normal;
            white-space: nowrap;
        }

        /* En el Word esta fila mide 2.04 cm, aunque su contenido ocupa la mitad; se reduce para que
           una solicitud sencilla quepa con sus firmas en una sola hoja. */
        .fila-costo {
            height: 1.1cm;
        }

        .indicaciones p {
            margin: 0;
            line-height: 1.15;
        }

        .aviso td {
            border: 1.5pt solid #c00000;
            color: #c00000;
            text-align: center;
            font-size: 10pt;
            padding: 0.02cm 0.19cm;
        }

        .aviso strong {
            font-size: 11pt;
            letter-spacing: 0.02em;
        }

        .aviso.autorizada td {
            border: 0.5pt solid #999999;
            color: #000;
        }

        .bloque {
            break-inside: avoid;
        }

        .bloque + .bloque {
            margin-top: 0.25cm;
        }

        .bloque h3 {
            break-after: avoid;
            margin: 0 0 0.05cm;
            font-size: 9pt;
            font-weight: bold;
            text-align: center;
        }

        .bloque p {
            margin: 0;
        }

        /* Tablas interiores (como las de «Requerimientos» del Word: bordes negros). */
        table.interior {
            width: 100%;
            margin: 0.1cm 0;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .interior th,
        .interior td {
            border: 0.5pt solid #000 !important;
            padding: 0.02cm 0.12cm !important;
            font-size: 9pt;
            vertical-align: top;
        }

        .interior th {
            font-weight: bold !important;
            text-align: center !important;
        }

        .interior tr {
            break-inside: avoid;
        }

        .interior ul {
            margin: 0;
            padding-left: 0.35cm;
        }

        /* ---------- Firmas: nunca se parten entre hojas ---------- */
        .firmas {
            break-inside: avoid;
            margin-top: -0.5pt;
        }

        .firmas td {
            text-align: center !important;
        }

        .firmas .espacio-firma {
            height: 1.5cm;
            border-bottom: none;
        }

        .firmas .nombre {
            border-top: none;
            vertical-align: bottom;
        }

        .firmas .nombre span {
            display: block;
        }

        .firmas .linea {
            display: block;
            margin: 0 auto 0.05cm;
            width: 85%;
            border-top: 0.5pt solid #000;
        }

        @media print {
            body {
                background: none;
            }

            .barra {
                display: none !important;
            }

            .hoja {
                width: 21.59cm;
                min-height: 0;
                margin: 0;
                box-shadow: none;
                overflow: visible;
            }

            /* En papel, el membrete y el pie se repiten en cada hoja. */
            .membrete,
            .pie {
                position: fixed;
            }
        }

        @media screen and (max-width: 22.5cm) {
            .hoja {
                margin: 8px 0;
            }
        }
    </style>
</head>
<body class="{{ $vistaPrevia ? 'previa' : '' }}">
    @unless ($vistaPrevia)
    <div class="barra" role="region" aria-label="Opciones de impresión">
        <p>
            Para imprimir o guardar como PDF: tamaño <strong>Carta</strong>, orientación <strong>vertical</strong>,
            márgenes <strong>Ninguno</strong>, escala <strong>100 %</strong> y <strong>Gráficos de fondo</strong> activado.
            Para el PDF elige el destino «Guardar como PDF».
        </p>
        <a href="{{ route('reservas.show', $reservaId) }}">Volver a la reserva</a>
        <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>
    @endunless

    <div class="hoja">
        <img class="membrete" src="{{ asset('images/membrete_formato10.jpg') }}" alt="Centro Universitario de Valladolid">

        <div class="pie">
            <span class="formato">FORMATO 10 DE ACTIVIDADES O EVENTOS</span>
        </div>

        <table class="marco" role="presentation">
            <thead><tr><td></td></tr></thead>
            <tfoot><tr><td></td></tr></tfoot>
            <tbody>
                <tr>
                    <td>
                        <div class="contenido">
                            <table class="f10">
                                <colgroup>
                                    <col style="width: 4.99cm">
                                    <col style="width: 4.25cm">
                                    <col style="width: 2.00cm">
                                    <col style="width: 1.75cm">
                                    <col style="width: 4.51cm">
                                </colgroup>
                                <tbody>
                                    <tr>
                                        <th colspan="5" class="gris titulo">FORMATO 10 DE ACTIVIDADES O EVENTOS</th>
                                    </tr>

                                    <tr class="aviso {{ $estado['autorizada'] ? 'autorizada' : '' }}">
                                        <td colspan="5">
                                            <strong>{{ $estado['titulo'] }}</strong> — {{ $estado['detalle'] }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td colspan="2" class="etiqueta encabezado-datos">
                                            <p>FECHA DE ELABORACIÓN: <span class="valor">{{ $elaboracion ?? 'No registrada' }}</span></p>
                                            <p>FECHA DE ENTREGA DE FORMATO: <span class="valor">{{ $entrega ?? 'No registrada' }}</span></p>
                                            <p>ACTUALIZACIÓN DE ESPACIOS: <span class="valor">Sin fecha registrada</span></p>
                                        </td>
                                        <td colspan="3" class="etiqueta">
                                            No. FOLIO: <span style="font-weight: normal">{{ $folioInstitucional ?? $folio }}</span>
                                            @if ($folioInstitucional && $folio)
                                                <span class="nota" style="font-weight: normal">Folio interno: {{ $folio }}</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td colspan="5" class="indicaciones">
                                            <p class="etiqueta">Indicaciones:</p>
                                            <p>1. Entregar el formato impreso en la dirección de licenciaturas.</p>
                                            <p>2. La autorización de las actividades será otorgada por el director.</p>
                                            <p>3. Este formato se deberá enviar impreso con al menos {{ $dias }} días de anticipación.</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th colspan="5" class="gris seccion">DATOS GENERALES</th>
                                    </tr>

                                    <tr>
                                        <td class="gris etiqueta">Nombre de la actividad:</td>
                                        <td colspan="4" class="centro t10">
                                            <span class="negrita">{{ $nombreActividad ?? $actividad }}</span>
                                            @if ($nombreActividad)
                                                <br>Tipo de actividad: {{ $actividad }}
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="gris etiqueta">Objetivo:</td>
                                        <td colspan="4" class="justificado t10 parrafo">@if ($objetivo !== ''){{ $objetivo }}@else<span class="vacio">Sin objetivo registrado</span>@endif</td>
                                    </tr>

                                    <tr>
                                        <td class="gris etiqueta">Licenciatura:</td>
                                        <td class="t10 negrita mayusculas">{{ $licenciatura }}</td>
                                        <td colspan="2" class="gris etiqueta">Cuatrimestre/Grupo</td>
                                        <td class="t10">@if ($cuatrimestre){{ $cuatrimestre }}@else<span class="vacio">Sin registrar</span>@endif</td>
                                    </tr>

                                    <tr>
                                        <td class="gris etiqueta">Asignatura vinculada:</td>
                                        <td class="t10">@if ($asignatura){{ $asignatura }}@else<span class="vacio">Sin asignatura vinculada</span>@endif</td>
                                        <td colspan="2" class="gris etiqueta">Cantidad de alumnos participantes:</td>
                                        <td class="t10">
                                            {{ $alumnos['texto'] }}
                                            @if ($alumnos['nota'])
                                                <span class="nota">{{ $alumnos['nota'] }}</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td rowspan="2" class="gris etiqueta">Fecha:</td>
                                        <td rowspan="2" class="t10 negrita">
                                            @forelse ($fechas as $fecha)
                                                {{ $fecha }}<br>
                                            @empty
                                                <span class="vacio" style="font-weight: normal">Sin fechas registradas</span>
                                            @endforelse
                                        </td>
                                        <td colspan="2" class="gris etiqueta">Hora de inicio:</td>
                                        <td class="t10">{{ $horario['inicio'] ?? 'Varía por bloque' }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="gris etiqueta">Hora de final:</td>
                                        <td class="t10">{{ $horario['fin'] ?? 'Varía por bloque' }}</td>
                                    </tr>

                                    <tr class="{{ count($bloques) > 6 ? 'largo' : '' }}">
                                        <td class="gris etiqueta">Lugar:</td>
                                        <td colspan="4" class="t9">
                                            @if (count($bloques) === 1)
                                                @php($b = $bloques[0])
                                                {{ $b['fecha_larga'] }}: {{ $b['espacio'] }}@if ($b['lugar']) — {{ $b['lugar'] }}@endif
                                            @elseif (count($bloques) > 1)
                                                <table class="interior">
                                                    <colgroup>
                                                        <col style="width: 20%">
                                                        <col style="width: 17%">
                                                        <col style="width: 24%">
                                                        <col style="width: 27%">
                                                        <col style="width: 12%">
                                                    </colgroup>
                                                    <thead>
                                                        <tr>
                                                            <th>Fecha</th>
                                                            <th>Horario</th>
                                                            <th>Espacio</th>
                                                            <th>Lugar específico</th>
                                                            <th>Alumnos</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($bloques as $b)
                                                            <tr>
                                                                <td>{{ $b['fecha_corta'] }}</td>
                                                                <td>{{ $b['inicio'] }} a {{ $b['fin'] }}</td>
                                                                <td>{{ $b['espacio'] }}</td>
                                                                <td>{{ $b['lugar'] ?? '—' }}</td>
                                                                <td class="centro">{{ $b['alumnos'] ?? '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @else
                                                <span class="vacio">Sin espacios registrados</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr class="largo">
                                        <td class="gris etiqueta">Descripción de la Actividad:<br>(Es importante incluir procedimientos, reseña de ponentes, detalles de principio a fin del desarrollo de la actividad).</td>
                                        <td colspan="4" class="t9">
                                            @php($conDescripcion = array_filter($bloques, fn ($b) => $b['descripcion'] || $b['docente']))
                                            @forelse ($conDescripcion as $b)
                                                <div class="bloque">
                                                    @if (count($bloques) > 1)
                                                        <h3>{{ ucfirst($b['fecha_larga']) }} · {{ $b['inicio'] }} a {{ $b['fin'] }} · {{ $b['espacio'] }}</h3>
                                                    @endif
                                                    @if ($b['docente'])
                                                        <p><span class="negrita">Docente responsable:</span> {{ $b['docente'] }}</p>
                                                    @endif
                                                    @if ($b['descripcion'])
                                                        <p class="parrafo justificado">{{ $b['descripcion'] }}</p>
                                                    @endif
                                                </div>
                                            @empty
                                                <span class="vacio">Sin descripción registrada</span>
                                            @endforelse
                                        </td>
                                    </tr>

                                    <tr class="largo">
                                        <td class="gris etiqueta">Requerimientos:</td>
                                        <td colspan="4" class="t9">
                                            @if (array_filter($bloques, fn ($b) => $b['requerimientos'] !== [] || $b['material']) === [])
                                                <span class="vacio">Sin requerimientos registrados</span>
                                            @else
                                                <table class="interior">
                                                    <colgroup>
                                                        <col style="width: 50%">
                                                        <col style="width: 50%">
                                                    </colgroup>
                                                    <thead>
                                                        <tr>
                                                            <th>Fecha, horario y lugar</th>
                                                            <th>Requerimientos</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($bloques as $b)
                                                            <tr>
                                                                <td>
                                                                    {{ $b['fecha_corta'] }}, {{ $b['inicio'] }} a {{ $b['fin'] }}<br>
                                                                    Lugar: {{ $b['espacio'] }}@if ($b['lugar']) — {{ $b['lugar'] }}@endif
                                                                </td>
                                                                <td>
                                                                    @if ($b['requerimientos'] !== [])
                                                                        <ul>
                                                                            @foreach ($b['requerimientos'] as $r)
                                                                                <li>{{ $r['nombre'] }}: {{ $r['cantidad'] }}</li>
                                                                            @endforeach
                                                                        </ul>
                                                                    @endif
                                                                    @if ($b['material'])
                                                                        <p style="margin: 0"><span class="negrita">Material adicional:</span> <span class="parrafo">{{ $b['material'] }}</span></p>
                                                                    @endif
                                                                    @if ($b['requerimientos'] === [] && ! $b['material'])
                                                                        <span class="vacio">Sin requerimientos</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr class="fila-costo">
                                        <td class="gris etiqueta">Costo para el alumno</td>
                                        <td class="t10">@if ($costo){{ $costo }}@else<span class="vacio">Sin registrar</span>@endif</td>
                                        <td colspan="2" class="gris etiqueta">Promoción en Redes Sociales</td>
                                        <td class="t10">@if ($promocion){{ $promocion }}@else<span class="vacio">Sin registrar</span>@endif</td>
                                    </tr>

                                    <tr class="largo">
                                        <td class="gris etiqueta">Observaciones, anexos, jueces, invitados etc.:</td>
                                        <td colspan="4" class="t9">
                                            @php($conObservaciones = array_filter($bloques, fn ($b) => $b['observaciones']))
                                            @forelse ($conObservaciones as $b)
                                                <div class="bloque">
                                                    @if (count($bloques) > 1)
                                                        <p class="negrita">{{ $b['fecha_corta'] }} · {{ $b['espacio'] }}:</p>
                                                    @endif
                                                    <p class="parrafo">{{ $b['observaciones'] }}</p>
                                                </div>
                                            @empty
                                                <span class="vacio">Sin observaciones</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <table class="f10 firmas">
                                <colgroup>
                                    <col style="width: 4.99cm">
                                    <col style="width: 6.25cm">
                                    <col style="width: 6.26cm">
                                </colgroup>
                                <tbody>
                                    <tr>
                                        @foreach ($firmas as $f)
                                            <td class="gris etiqueta">{{ $f['titulo'] }}</td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        @foreach ($firmas as $f)
                                            <td class="espacio-firma" aria-label="Espacio para firma"></td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        @foreach ($firmas as $f)
                                            <td class="nombre t10">
                                                <span class="linea"></span>
                                                <span class="negrita">{{ $f['nombre'] ?? "\u{00A0}" }}</span>
                                                <span class="nota">{{ $f['detalle'] }}</span>
                                            </td>
                                        @endforeach
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($vistaPrevia)
    {{-- Vista previa del detalle (VER): la MISMA hoja de arriba, repartida en pantalla en hojas
         carta consecutivas con las medidas de impresión (membrete y pie en cada hoja; contenido
         desde 4.36 cm en la primera y 5 cm en las demás, hasta 2.5 cm del borde inferior). Las
         filas cortas y las firmas no se parten; las filas largas se dividen por bloques y las
         tablas interiores repiten su encabezado, como al imprimir. Solo reacomoda en pantalla;
         la impresión del detalle usa la ruta normal, sin este modo. --}}
    <style>
        body.previa {
            background: transparent;
        }

        .paginas {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding: 8px 0 16px;
        }

        .pagina {
            position: relative;
            flex: none;
            width: 21.59cm;
            height: 27.94cm;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .25);
            overflow: hidden;
        }

        .pagina .membrete,
        .pagina .pie {
            position: absolute !important;
        }

        .pagina .area {
            position: absolute;
            left: 2.045cm;
            width: 17.5cm;
            overflow: hidden;
        }

        @media print {
            .paginas {
                zoom: 1 !important;
                gap: 0;
                padding: 0;
            }

            .pagina {
                box-shadow: none;
                break-after: page;
            }
        }
    </style>
    <script>
        (function () {
            const hoja = document.querySelector('.hoja');
            const contenido = hoja.querySelector('.contenido');
            const membrete = hoja.querySelector('.membrete');
            const pie = hoja.querySelector('.pie');
            const paginas = document.createElement('div');
            paginas.className = 'paginas';
            let area = null;
            let cuerpo = null;
            let total = 0;

            const nuevaPagina = () => {
                const pagina = document.createElement('div');
                pagina.className = 'pagina';
                pagina.setAttribute('aria-label', 'Hoja ' + (total + 1));
                pagina.append(membrete.cloneNode(true), pie.cloneNode(true));
                area = document.createElement('div');
                area.className = 'area';
                const arriba = total === 0 ? 4.36 : 5;
                area.style.top = arriba + 'cm';
                area.style.height = (27.94 - 2.5 - arriba) + 'cm';
                pagina.appendChild(area);
                paginas.appendChild(pagina);
                total++;
            };
            const desborda = () => area.scrollHeight > area.clientHeight + 1;
            const nuevaTabla = (origen) => {
                const tabla = origen.cloneNode(false);
                const columnas = origen.querySelector(':scope > colgroup');
                if (columnas) {
                    tabla.appendChild(columnas.cloneNode(true));
                }
                cuerpo = document.createElement('tbody');
                tabla.appendChild(cuerpo);
                area.appendChild(tabla);
            };

            // Piezas de una celda larga: sus elementos, y las filas de sus tablas interiores.
            const piezasDe = (celda) => {
                const piezas = [];
                for (const nodo of [...celda.childNodes]) {
                    if (nodo.nodeType === Node.TEXT_NODE && nodo.textContent.trim() === '') {
                        continue;
                    }
                    if (nodo.nodeType === Node.ELEMENT_NODE && nodo.matches('table.interior')) {
                        for (const fila of nodo.querySelectorAll(':scope > tbody > tr')) {
                            piezas.push({ tabla: nodo, fila });
                        }
                    } else {
                        piezas.push({ nodo });
                    }
                }
                return piezas;
            };
            const crearParte = (fila, etiqueta, celda, primera) => {
                const tr = fila.cloneNode(false);
                const td = celda.cloneNode(false);
                tr.append(primera ? etiqueta : etiqueta.cloneNode(false), td);
                return { tr, td, interior: null, origen: null };
            };
            const colocar = (parte, pieza) => {
                if (pieza.nodo) {
                    parte.td.appendChild(pieza.nodo);
                    parte.interior = null;
                    return;
                }
                if (parte.origen !== pieza.tabla) {
                    const copia = pieza.tabla.cloneNode(false);
                    for (const hijo of pieza.tabla.querySelectorAll(':scope > colgroup, :scope > thead')) {
                        copia.appendChild(hijo.cloneNode(true));
                    }
                    parte.interior = document.createElement('tbody');
                    copia.appendChild(parte.interior);
                    parte.td.appendChild(copia);
                    parte.origen = pieza.tabla;
                }
                parte.interior.appendChild(pieza.fila);
            };
            const quitar = (parte, pieza) => {
                if (pieza.nodo) {
                    pieza.nodo.remove();
                    return;
                }
                pieza.fila.remove();
                if (parte.interior && parte.interior.children.length === 0) {
                    parte.interior.parentElement.remove();
                    parte.interior = null;
                    parte.origen = null;
                }
            };
            const partir = (origen, fila) => {
                const [etiqueta, celda] = fila.children;
                const piezas = piezasDe(celda);
                let parte = crearParte(fila, etiqueta, celda, true);
                cuerpo.appendChild(parte.tr);
                for (const pieza of piezas) {
                    colocar(parte, pieza);
                    if (!desborda()) {
                        continue;
                    }
                    quitar(parte, pieza);
                    const vacia = parte.td.childNodes.length === 0;
                    if (vacia && area.children.length === 1 && cuerpo.children.length === 1) {
                        colocar(parte, pieza); // No cabe ni sola en una hoja: se deja aquí.
                        continue;
                    }
                    if (vacia) {
                        parte.tr.remove();
                    }
                    nuevaPagina();
                    nuevaTabla(origen);
                    parte = crearParte(fila, etiqueta, celda, vacia);
                    cuerpo.appendChild(parte.tr);
                    colocar(parte, pieza);
                }
            };
            const repartir = (origen, indivisible) => {
                const filas = [...origen.querySelector(':scope > tbody').children];
                nuevaTabla(origen);
                if (indivisible) {
                    filas.forEach((fila) => cuerpo.appendChild(fila));
                    if (desborda() && area.children.length > 1) {
                        cuerpo.parentElement.remove();
                        nuevaPagina();
                        nuevaTabla(origen);
                        filas.forEach((fila) => cuerpo.appendChild(fila));
                    }
                    return;
                }
                for (const fila of filas) {
                    cuerpo.appendChild(fila);
                    if (!desborda()) {
                        continue;
                    }
                    if (fila.classList.contains('largo')) {
                        fila.remove();
                        partir(origen, fila);
                        continue;
                    }
                    if (area.children.length === 1 && cuerpo.children.length === 1) {
                        continue; // No cabe ni sola en una hoja: se deja aquí.
                    }
                    fila.remove();
                    if (cuerpo.children.length === 0) {
                        cuerpo.parentElement.remove();
                    }
                    nuevaPagina();
                    nuevaTabla(origen);
                    cuerpo.appendChild(fila);
                }
            };
            // Ancho de una hoja carta (21.59 cm) en píxeles CSS.
            const ANCHO_HOJA = 21.59 * 96 / 2.54;
            const ajustar = () => {
                paginas.style.zoom = Math.min(1, (window.innerWidth - 8) / ANCHO_HOJA);
                if (window.parent !== window) {
                    // Borde inferior real de las hojas (scrollHeight nunca baja de la altura del marco).
                    const alto = Math.ceil(paginas.getBoundingClientRect().bottom + window.scrollY);
                    window.parent.postMessage({ tipo: 'formato10-altura', alto }, window.location.origin);
                }
            };
            const paginar = () => {
                document.body.appendChild(paginas);
                nuevaPagina();
                const tablas = [...contenido.querySelectorAll(':scope > table')];
                for (const tabla of tablas) {
                    repartir(tabla, tabla.classList.contains('firmas'));
                }
                hoja.remove();
                ajustar();
                window.addEventListener('resize', ajustar);
            };

            const listo = document.fonts ? document.fonts.ready : Promise.resolve();
            listo.then(paginar);
        })();
    </script>
    @endif
</body>
</html>
