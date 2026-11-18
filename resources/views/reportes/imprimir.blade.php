{{--
    Reporte de reservación de espacios, versión imprimible.

    Misma presentación que el Formato 10 (reservas/imprimir.blade.php): papel carta vertical con
    el membrete institucional de página completa, que el navegador repite en cada hoja, y tablas
    de 17.5 cm centradas con bordes de 0.5 pt #999999 y sombreado #D9D9D9. El encabezado de la
    tabla de detalle se repite en cada hoja y ninguna fila se parte entre hojas.
    Solo muestra datos; no tiene formularios ni escribe nada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Reporte de reservaciones {{ $periodo }}</title>
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
            font-family: Calibri, Carlito, 'Liberation Sans', Arial, sans-serif;
            font-size: 9pt;
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
            border-bottom: 3px solid #8b1a2b;
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
            border: 1px solid #8b1a2b;
            cursor: pointer;
            text-decoration: none;
        }

        .barra button {
            background: #8b1a2b;
            color: #fff;
            font-weight: 600;
        }

        .barra a {
            background: #fff;
            color: #8b1a2b;
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

        .pie {
            position: absolute;
            left: 2.05cm;
            right: 2.05cm;
            bottom: 1.25cm;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            font-family: Arial, 'Liberation Sans', sans-serif;
            font-size: 8pt;
        }

        /* Encabezado y pie de la tabla «marco»: reservan en cada hoja el espacio del membrete. */
        table.marco {
            position: relative;
            z-index: 1;
            width: 100%;
            border-collapse: collapse;
        }

        table.marco > thead > tr > td {
            height: 4.7cm;
            padding: 0;
        }

        table.marco > tfoot > tr > td {
            height: 2.6cm;
            padding: 0;
        }

        table.marco > tbody > tr > td {
            padding: 0;
        }

        .contenido {
            width: 17.5cm;
            margin: 0 auto;
        }

        /* ---------- Título ---------- */
        .titulo {
            margin: 0 0 0.15cm;
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .subtitulo {
            margin: 0 0 0.35cm;
            text-align: center;
            font-size: 10pt;
        }

        /* ---------- Tablas ---------- */
        table.datos {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 0.4cm;
        }

        table.datos th,
        table.datos td {
            border: 0.5pt solid #999;
            padding: 0.06cm 0.12cm;
            vertical-align: middle;
            text-align: left;
            overflow-wrap: anywhere;
        }

        table.datos th,
        table.datos td.gris {
            background: #d9d9d9;
            font-weight: bold;
        }

        table.datos caption {
            caption-side: top;
            padding: 0.08cm 0.12cm;
            border: 0.5pt solid #999;
            border-bottom: none;
            background: #d9d9d9;
            color: #000;
            font-weight: bold;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        table.datos .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        table.datos tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        table.detalle {
            font-size: 8pt;
        }

        table.detalle thead {
            display: table-header-group;
        }

        table.detalle th {
            font-size: 7.5pt;
            text-transform: uppercase;
        }

        .resumenes {
            display: flex;
            gap: 0.5cm;
            align-items: flex-start;
        }

        .resumenes > table {
            flex: 1 1 0;
        }

        tr.total td {
            background: #f2f2f2;
            font-weight: bold;
        }

        .vacio {
            color: #666;
            font-style: italic;
            text-align: center !important;
            padding: 0.3cm !important;
        }

        /* ---------- Firma: nunca se parte entre hojas ---------- */
        .firma {
            break-inside: avoid;
            page-break-inside: avoid;
            width: 7cm;
            margin: 1.2cm auto 0;
            text-align: center;
        }

        .firma .linea {
            display: block;
            border-top: 0.5pt solid #000;
            margin-bottom: 0.05cm;
        }

        @media print {
            body {
                background: none;
            }

            .barra {
                display: none !important;
            }

            .hoja {
                min-height: 0;
                zoom: 1 !important;
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
<body>
    <div class="barra" role="region" aria-label="Opciones de impresión">
        <p>
            Para imprimir o guardar como PDF: tamaño <strong>Carta</strong>, orientación <strong>vertical</strong>,
            márgenes <strong>Ninguno</strong>, escala <strong>100 %</strong> y <strong>Gráficos de fondo</strong> activado.
        </p>
        <a href="{{ $volver }}">Volver a reportes</a>
        <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <div class="hoja">
        <img class="membrete" src="{{ asset('images/membrete_formato10.jpg') }}" alt="Centro Universitario de Valladolid">

        <div class="pie">
            <span>Generado el {{ $generado }}</span>
            <span>REPORTE DE RESERVACIÓN DE ESPACIOS</span>
        </div>

        <table class="marco" role="presentation">
            <thead><tr><td></td></tr></thead>
            <tfoot><tr><td></td></tr></tfoot>
            <tbody>
                <tr>
                    <td>
                        <div class="contenido">
                            <h1 class="titulo">Reporte de reservación de espacios</h1>
                            <p class="subtitulo">Periodo del {{ $periodo }}</p>

                            <table class="datos">
                                <colgroup>
                                    <col style="width: 3.5cm">
                                    <col>
                                    <col style="width: 3.5cm">
                                    <col style="width: 3cm">
                                </colgroup>
                                <tr>
                                    <td class="gris">Elaboró:</td>
                                    <td>{{ $elaboro }}</td>
                                    <td class="gris">Total de reservaciones:</td>
                                    <td class="num">{{ count($resultados) }}</td>
                                </tr>
                                <tr>
                                    <td class="gris">Filtros:</td>
                                    <td colspan="3">
                                        @forelse ($aplicados as $filtro => $valor)
                                            {{ $filtro }}: <strong>{{ $valor }}</strong>@if (! $loop->last) · @endif
                                        @empty
                                            Todos los espacios, licenciaturas y estados
                                        @endforelse
                                    </td>
                                </tr>
                            </table>

                            <div class="resumenes">
                                <table class="datos">
                                    <caption>Resumen por estado</caption>
                                    <colgroup><col><col style="width: 2cm"></colgroup>
                                    <thead><tr><th>Estado</th><th class="num">Total</th></tr></thead>
                                    <tbody>
                                        @forelse ($totalesEstado as $fila)
                                            <tr><td>{{ $fila['nombre'] }}</td><td class="num">{{ $fila['total'] }}</td></tr>
                                        @empty
                                            <tr><td colspan="2" class="vacio">Sin reservaciones</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>

                                <table class="datos">
                                    <caption>Uso por espacio</caption>
                                    <colgroup><col><col style="width: 2cm"></colgroup>
                                    <thead><tr><th>Espacio</th><th class="num">Total</th></tr></thead>
                                    <tbody>
                                        @forelse ($totalesEspacio as $fila)
                                            <tr><td>{{ $fila['nombre'] }}</td><td class="num">{{ $fila['total'] }}</td></tr>
                                        @empty
                                            <tr><td colspan="2" class="vacio">Sin reservaciones</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <table class="datos detalle">
                                <caption>Detalle de reservaciones</caption>
                                <colgroup>
                                    <col style="width: 2.2cm">
                                    <col style="width: 1.6cm">
                                    <col style="width: 1.9cm">
                                    <col style="width: 2.4cm">
                                    <col style="width: 3.4cm">
                                    <col style="width: 2.3cm">
                                    <col style="width: 2.1cm">
                                    <col style="width: 1.6cm">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Folio</th>
                                        <th>Fecha</th>
                                        <th>Horario</th>
                                        <th>Espacio</th>
                                        <th>Actividad</th>
                                        <th>Solicitante</th>
                                        <th>Licenciatura</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($resultados as $fila)
                                        <tr>
                                            <td>{{ $fila['folio'] }}</td>
                                            <td class="num">{{ $fila['fecha_corta'] }}</td>
                                            <td class="num">{{ $fila['hora_inicio'] }}–{{ $fila['hora_fin'] }}</td>
                                            <td>{{ $fila['espacio'] }}</td>
                                            <td>{{ $fila['actividad'] }}</td>
                                            <td>{{ $fila['docente'] }}</td>
                                            <td>{{ $fila['licenciatura'] }}</td>
                                            <td>{{ $fila['estado'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="vacio">No hay reservaciones en este periodo con los filtros elegidos.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>

                            <div class="firma">
                                <span class="linea"></span>
                                {{ $elaboro }}<br>
                                Elaboró
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <script>
        // En pantallas más angostas que la hoja carta, la hoja se reduce para verse completa
        // sin desplazarse de lado. Solo en pantalla: al imprimir se usa el tamaño real.
        (function () {
            const hoja = document.querySelector('.hoja');
            const ajustar = () => {
                hoja.style.zoom = '';
                const disponible = document.documentElement.clientWidth - 16;
                const ancho = hoja.offsetWidth;
                hoja.style.zoom = ancho > disponible ? String(disponible / ancho) : '';
            };
            ajustar();
            window.addEventListener('resize', ajustar);
            window.addEventListener('beforeprint', () => { hoja.style.zoom = ''; });
            window.addEventListener('afterprint', ajustar);
        })();
    </script>
</body>
</html>
