import { Head, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { about } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Acerca de', href: about().url },
];

const ENV_LABELS: Record<string, string> = {
    production: 'Producción',
    staging: 'Staging',
    local: 'Local',
    testing: 'Pruebas',
};

const ENV_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    production: 'default',
    staging: 'destructive',
    local: 'secondary',
    testing: 'outline',
};

export default function About() {
    const { name, tagline, config } = usePage().props;
    const version = config.version;
    const environment = config.environment;
    const releaseUrl =
        version && version !== 'dev'
            ? `https://github.com/cristian-home/sgte-app/releases/tag/v${version}`
            : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Acerca de" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-3">
                    <Info className="size-6 text-muted-foreground" />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Acerca de {name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {tagline}
                        </p>
                    </div>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Información de la instalación</CardTitle>
                        <CardDescription>
                            Versión y entorno del despliegue actual.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1">
                            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                Versión
                            </span>
                            {releaseUrl ? (
                                <a
                                    href={releaseUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono text-base text-primary hover:underline"
                                >
                                    v{version}
                                </a>
                            ) : (
                                <span className="font-mono text-base">
                                    {version}
                                </span>
                            )}
                        </div>
                        <div className="flex flex-col gap-1">
                            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                Entorno
                            </span>
                            <Badge
                                variant={
                                    ENV_VARIANTS[environment] ?? 'secondary'
                                }
                                className="w-fit"
                            >
                                {ENV_LABELS[environment] ?? environment}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Sobre el sistema</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm/6 text-muted-foreground">
                        <p>
                            SGTE es el Sistema de Gestión de Transporte Especial
                            para flotas en Colombia. Cubre la operación diaria
                            de servicios, conductores y vehículos, junto con
                            facturación, novedades y generación de documentos
                            FUEC.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
