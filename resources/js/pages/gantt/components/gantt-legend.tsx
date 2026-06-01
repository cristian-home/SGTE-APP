import { Info } from 'lucide-react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

interface SwatchProps {
    className: string;
    children: React.ReactNode;
}

function Swatch({ className, children }: SwatchProps) {
    return (
        <span className="flex items-center gap-1 lg:gap-1.5">
            <span
                aria-hidden
                className={'inline-block size-3 rounded-sm ' + className}
            />
            <span>{children}</span>
        </span>
    );
}

/**
 * Always-visible legend strip rendered between the page-level header
 * and the timeline grid. Covers the 4 service-status colors + the AHORA
 * line — the most-used signals. Secondary info (day status, vehicle
 * badges, blocked row) lives behind a "Más" popover so the strip stays
 * compact on narrow viewports.
 */
export default function GanttLegend() {
    return (
        <div className="flex flex-wrap items-center gap-1.5 overflow-x-clip rounded-md border bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground lg:gap-x-4">
            <Swatch className="bg-orange-400 dark:bg-orange-500">
                Abierto
            </Swatch>
            <Swatch className="bg-green-500 dark:bg-green-600">Cerrado</Swatch>
            <Swatch className="bg-red-500 dark:bg-red-600">Declinado</Swatch>
            <Swatch className="bg-zinc-400 opacity-70 dark:bg-zinc-600">
                Bloqueado
            </Swatch>
            {/* <span className="flex items-center gap-1.5">
                <span
                    aria-hidden
                    className="inline-block h-3 w-0.5 bg-red-500"
                />
                Ahora
            </span> */}

            <Popover>
                <PopoverTrigger className="ml-auto inline-flex items-center gap-1 rounded text-xs text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <Info className="size-3.5" />
                    <span className="hidden md:block">Más</span>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-72 space-y-3 text-xs">
                    <div className="space-y-1">
                        <p className="font-medium text-foreground">
                            Estado del día
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span className="inline-flex rounded-md bg-orange-100 px-1.5 py-0.5 text-[10px] font-medium text-orange-700 dark:bg-orange-900 dark:text-orange-300">
                                Proyectado
                            </span>
                            <span>Día en planificación, editable.</span>
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span className="inline-flex rounded-md bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-900 dark:text-green-300">
                                Ejecutado
                            </span>
                            <span>Día cerrado, sin más ediciones.</span>
                        </p>
                    </div>

                    <div className="space-y-1">
                        <p className="font-medium text-foreground">
                            Etiquetas de vehículo
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span className="inline-flex rounded-md bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                3ro
                            </span>
                            <span>Vehículo de tercero (proveedor).</span>
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span className="inline-flex rounded-md bg-yellow-100 px-1.5 py-0.5 text-[10px] font-medium text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300">
                                Prec.
                            </span>
                            <span>Algún documento vence en ≤15 días.</span>
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span className="inline-flex rounded-md bg-red-600 px-1.5 py-0.5 text-[10px] font-medium text-white">
                                BLOQ.
                            </span>
                            <span>Documento vencido.</span>
                        </p>
                    </div>

                    <div className="space-y-1">
                        <p className="font-medium text-foreground">Otros</p>
                        <p>
                            Fila atenuada: vehículo bloqueado por documentos
                            vencidos.
                        </p>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}
