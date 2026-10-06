<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Archive, ClipboardList, Copy, LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-vue-next';

interface Fermata {
    id: number;
    nome: string;
    orario: string;
    destinazione: boolean;
}

interface Persona {
    id: number;
    nome: string;
}

const props = defineProps<{
    linea: { id: number; nome: string; fermate_precedenti_visibili: number };
    fermate: Fermata[];
    responsabili: Persona[];
    assegnati: number[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Linee', href: '/linee' },
    { title: props.linea.nome, href: '#' },
];

const formNome = useForm({ nome: props.linea.nome });
const formResponsabili = useForm({ responsabili: [...props.assegnati] });
const formVisibilita = useForm({ fermate_precedenti_visibili: props.linea.fermate_precedenti_visibili });

const salvaNome = () => formNome.put(route('linee.update', props.linea.id), { preserveScroll: true });
const salvaVisibilita = () => formVisibilita.put(route('linee.visibilita', props.linea.id), { preserveScroll: true });
const salvaResponsabili = () => formResponsabili.put(route('linee.responsabili', props.linea.id), { preserveScroll: true });

const eliminaFermata = (fermata: Fermata) => {
    if (confirm(`Eliminare la fermata «${fermata.nome}»?`)) {
        router.delete(route('fermate.destroy', fermata.id), { preserveScroll: true });
    }
};

const archiviaLinea = () => {
    if (confirm(`Archiviare la linea «${props.linea.nome}»? Sparirà dall'elenco e solo l'amministratore globale potrà ripristinarla.`)) {
        router.post(route('linee.archivia', props.linea.id));
    }
};

const eliminaLinea = () => {
    if (confirm(`Eliminare la linea «${props.linea.nome}» con tutte le sue fermate? L'operazione non si può annullare.`)) {
        router.delete(route('linee.destroy', props.linea.id));
    }
};
</script>

<template>
    <Head :title="`Linea ${linea.nome}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-3xl flex-col gap-10 p-4">
            <Heading :title="linea.nome" description="Nome, fermate e responsabili della linea." />

            <section class="space-y-4">
                <HeadingSmall title="Nome" />
                <form class="flex flex-wrap items-start gap-3" @submit.prevent="salvaNome">
                    <div class="grid min-w-64 flex-1 gap-2">
                        <Label for="nome" class="sr-only">Nome della linea</Label>
                        <Input id="nome" v-model="formNome.nome" required autocomplete="off" />
                        <InputError :message="formNome.errors.nome" />
                    </div>
                    <Button type="submit" :disabled="formNome.processing">
                        <LoaderCircle v-if="formNome.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>
                </form>
            </section>

            <section class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <HeadingSmall title="Fermate" description="Sono sempre in ordine di orario." />
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('assegnazioni.linea', linea.id)"><ClipboardList class="h-4 w-4" /> Accompagnatori e bambini</Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="route('linee.duplica.form', linea.id)"><Copy class="h-4 w-4" /> Duplica la linea</Link>
                        </Button>
                        <Button as-child>
                            <Link :href="route('fermate.create', linea.id)"><Plus class="h-4 w-4" /> Aggiungi una fermata</Link>
                        </Button>
                    </div>
                </div>

                <div v-if="fermate.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Questa linea non ha ancora fermate.
                </div>

                <div v-else class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Orario</th>
                                <th class="px-4 py-3 font-medium">Fermata</th>
                                <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="fermata in fermate" :key="fermata.id" class="border-t">
                                <td class="px-4 py-3 font-medium tabular-nums">{{ fermata.orario }}</td>
                                <td class="px-4 py-3">
                                    {{ fermata.nome }}
                                    <span v-if="fermata.destinazione" class="ml-2 rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">Destinazione</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="route('fermate.edit', fermata.id)"><Pencil class="h-4 w-4" /> Modifica</Link>
                                    </Button>
                                    <Button variant="ghost" size="sm" class="text-red-600" @click="eliminaFermata(fermata)">
                                        <Trash2 class="h-4 w-4" /> Elimina
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-4">
                <HeadingSmall title="Responsabili" description="Chi assegna accompagnatori e bambini alle fermate di questa linea." />

                <p v-if="responsabili.length === 0" class="text-sm text-muted-foreground">
                    Nella tua città non ci sono ancora responsabili.
                    <Link :href="route('persone.create')" class="underline">Invita una persona</Link> con il ruolo di responsabile.
                </p>

                <form v-else class="space-y-4" @submit.prevent="salvaResponsabili">
                    <label v-for="persona in responsabili" :key="persona.id" class="flex items-center gap-3 text-sm">
                        <input v-model="formResponsabili.responsabili" type="checkbox" :value="persona.id" />
                        {{ persona.nome }}
                    </label>
                    <InputError :message="formResponsabili.errors.responsabili" />
                    <Button type="submit" :disabled="formResponsabili.processing">
                        <LoaderCircle v-if="formResponsabili.processing" class="h-4 w-4 animate-spin" />
                        Salva i responsabili
                    </Button>
                </form>
            </section>

            <section class="space-y-4">
                <HeadingSmall title="Fermate prima di quella di inizio" description="Oltre alle proprie fermate, gli accompagnatori possono lavorare anche su quelle appena prima." />

                <form class="space-y-3" @submit.prevent="salvaVisibilita">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <label for="precedenti">Gli accompagnatori possono vedere</label>
                        <Input
                            id="precedenti"
                            v-model.number="formVisibilita.fermate_precedenti_visibili"
                            type="number"
                            min="0"
                            max="99"
                            step="1"
                            inputmode="numeric"
                            class="w-20"
                            required
                        />
                        <span>fermate prima della loro</span>
                    </div>
                    <InputError :message="formVisibilita.errors.fermate_precedenti_visibili" />
                    <p class="text-sm text-muted-foreground">
                        Su quelle fermate vedono i bambini e possono segnarli presenti o assenti (e aggiungerne uno per il giorno), come sulle proprie e con le stesse regole: fino a 30 minuti dopo
                        l'arrivo previsto, anche senza connessione. Con 0 lavorano solo dalla propria fermata in poi e delle precedenti vedono solo nome e orario.
                    </p>
                    <Button type="submit" :disabled="formVisibilita.processing">
                        <LoaderCircle v-if="formVisibilita.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>
                </form>
            </section>

            <section class="space-y-3 rounded-lg border p-4">
                <p class="font-medium">Archivia la linea</p>
                <p class="text-sm text-muted-foreground">
                    Per le linee che non servono più. La linea e le sue presenze non vengono cancellate, ma sparisce dall'elenco e dagli accompagnatori.
                    Solo l'amministratore globale può ripristinarla.
                </p>
                <Button variant="outline" @click="archiviaLinea"><Archive class="h-4 w-4" /> Archivia la linea</Button>
            </section>

            <section class="space-y-3 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <p class="font-medium text-red-600 dark:text-red-100">Elimina la linea</p>
                <p class="text-sm text-red-600 dark:text-red-100">
                    Vengono eliminate anche le sue fermate. Una linea con presenze registrate non si può eliminare: in quel caso archiviala.
                </p>
                <Button variant="destructive" @click="eliminaLinea"><Trash2 class="h-4 w-4" /> Elimina la linea</Button>
            </section>
        </div>
    </AppLayout>
</template>
