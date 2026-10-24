<?php

namespace App\Services\Catalog;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Import et export du catalogue en CSV ou Excel (XLSX) : une ligne par variante. Les
 * colonnes connues sont fixes ; toute autre colonne est une option (taille, couleur,
 * pointure…). Le CSV utilise le point-virgule, comme Excel en français.
 */
class CatalogSpreadsheet
{
    public const COLUMNS = ['produit', 'categorie', 'description', 'prix_base', 'actif', 'prix_variante', 'stock', 'seuil_stock_faible', 'sku'];

    /** Options proposées dans le modèle ; le gérant peut en ajouter d'autres colonnes. */
    public const TEMPLATE_OPTIONS = ['taille', 'couleur'];

    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    /**
     * Importer un fichier : articles retrouvés par leur nom, variantes par leur SKU ou leurs
     * options. Chaque ligne est traitée seule ; les lignes en erreur sont rapportées.
     *
     * @return array{created_products: int, created_variants: int, updated_variants: int, errors: list<array{line: int, message: string}>}
     */
    public function import(Business $business, string $path, string $extension, ?int $userId = null): array
    {
        $report = ['created_products' => 0, 'created_variants' => 0, 'updated_variants' => 0, 'errors' => []];
        $rows = $this->read($path, $extension);
        $header = array_map(fn ($cell) => $this->column((string) $cell), array_shift($rows) ?? []);

        if (! in_array('produit', $header, true)) {
            $report['errors'][] = ['line' => 1, 'message' => 'Colonne « produit » introuvable : partez du modèle à télécharger.'];

            return $report;
        }

        if (count($rows) > self::MAX_ROWS) {
            $report['errors'][] = ['line' => 1, 'message' => 'Le fichier dépasse '.self::MAX_ROWS.' lignes : découpez-le en plusieurs fichiers.'];

            return $report;
        }

        $touched = [];

        foreach ($rows as $index => $cells) {
            $line = $index + 2;
            $values = [];
            foreach ($header as $position => $name) {
                if ($name !== '') {
                    $values[$name] = trim((string) ($cells[$position] ?? ''));
                }
            }

            if (implode('', $values) === '') {
                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->importRow($business, $values, $userId));
                $report['created_products'] += $result['product_created'] ? 1 : 0;
                $report[$result['variant_created'] ? 'created_variants' : 'updated_variants']++;
                $touched[$result['product']->id] = $result['product'];
            } catch (ImportRowException $e) {
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        foreach ($touched as $product) {
            $this->catalog->embed($product);
        }

        return $report;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{product: Product, product_created: bool, variant_created: bool}
     *
     * @throws ImportRowException
     */
    private function importRow(Business $business, array $values, ?int $userId): array
    {
        $name = $values['produit'] ?? '';
        if ($name === '') {
            throw new ImportRowException('Le nom du produit est obligatoire.');
        }

        $basePrice = $this->number($values['prix_base'] ?? '', 'prix_base');
        $variantPrice = $this->number($values['prix_variante'] ?? '', 'prix_variante');
        $stock = $this->integer($values['stock'] ?? '', 'stock');
        $threshold = $this->integer($values['seuil_stock_faible'] ?? '', 'seuil_stock_faible');
        $active = $this->boolean($values['actif'] ?? '');

        $options = CatalogService::normalizeOptions(array_diff_key($values, array_flip(self::COLUMNS)));

        $product = $business->products()->get()->first(fn (Product $product) => CatalogService::normalize($product->name) === CatalogService::normalize($name));
        $productCreated = $product === null;

        if ($productCreated) {
            if ($basePrice === null) {
                throw new ImportRowException("Prix de base manquant pour le nouveau produit « {$name} ».");
            }

            $product = $business->products()->create([
                'name' => $name,
                'category' => ($values['categorie'] ?? '') ?: null,
                'description' => ($values['description'] ?? '') ?: null,
                'base_price' => $basePrice,
                'is_active' => $active ?? true,
            ]);
        } else {
            $product->update(array_filter([
                'category' => ($values['categorie'] ?? '') ?: null,
                'description' => ($values['description'] ?? '') ?: null,
                'base_price' => $basePrice,
                'is_active' => $active,
            ], fn ($value) => $value !== null));
        }

        $sku = ($values['sku'] ?? '') ?: null;
        $variants = $product->variants()->get();
        $variant = ($sku !== null ? $variants->firstWhere('sku', $sku) : null)
            ?? $variants->first(fn (ProductVariant $variant) => CatalogService::sameOptions($variant->options ?? [], $options));

        // Un article simple créé sans options reçoit sa première variante.
        if ($variant === null && $options !== []) {
            $variants->filter(fn (ProductVariant $variant) => $variant->options === [] && $variant->stock_quantity === null && $variant->sku === null)
                ->each(fn (ProductVariant $variant) => $variant->delete());
        }

        $variantCreated = $variant === null;
        $variant ??= $product->variants()->create(['options' => $options]);

        $variant->update(array_filter([
            'price' => $variantPrice,
            'low_stock_threshold' => $threshold,
            'sku' => $sku,
        ], fn ($value) => $value !== null));

        // Stock vide : inchangé (non suivi pour une nouvelle variante).
        if ($stock !== null) {
            $this->catalog->setStock($variant, $stock, StockMovement::IMPORT, $userId);
        }

        return ['product' => $product, 'product_created' => $productCreated, 'variant_created' => $variantCreated];
    }

    /**
     * Fichier d'export (ou modèle vide si $business est null) ; chemin d'un fichier temporaire.
     */
    public function write(?Business $business, string $format): string
    {
        $variants = $business === null ? collect() : ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn ($query) => $query->where('business_id', $business->id))
            ->get()
            ->sortBy(fn (ProductVariant $variant) => mb_strtolower($variant->product->name).'|'.$variant->created_at)
            ->values();

        $optionNames = $this->optionNames($variants);
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.'.$format;

        $writer = $format === 'csv' ? new CsvWriter($this->csvWriterOptions()) : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([...self::COLUMNS, ...$optionNames]));

        if ($business === null) {
            $writer->addRow(Row::fromValues(['Robe wax Awa', 'Robes', 'Robe longue en wax', 12500, 'oui', '', 5, 2, 'RWA-M-ROUGE', 'M', 'rouge']));
            $writer->addRow(Row::fromValues(['Robe wax Awa', 'Robes', 'Robe longue en wax', 12500, 'oui', 13000, 3, 2, 'RWA-L-ROUGE', 'L', 'rouge']));
            $writer->addRow(Row::fromValues(['Pagne wax 6 yards', 'Pagnes', '', 7500, 'oui', '', '', '', '', '', '']));
        }

        foreach ($variants as $variant) {
            $product = $variant->product;
            $writer->addRow(Row::fromValues([
                $product->name,
                (string) $product->category,
                (string) $product->description,
                $product->base_price,
                $product->is_active ? 'oui' : 'non',
                $variant->price ?? '',
                $variant->stock_quantity ?? '',
                $variant->low_stock_threshold,
                (string) $variant->sku,
                ...array_map(fn (string $name) => (string) ($variant->options[$name] ?? ''), $optionNames),
            ]));
        }

        $writer->close();

        return $path;
    }

    /**
     * Noms d'options du catalogue, taille et couleur d'abord.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<string>
     */
    private function optionNames(Collection $variants): array
    {
        $names = $variants->flatMap(fn (ProductVariant $variant) => array_keys((array) $variant->options))->unique()->sort()->values()->all();

        if ($names === []) {
            return self::TEMPLATE_OPTIONS;
        }

        return array_values(array_unique([...array_values(array_intersect(self::TEMPLATE_OPTIONS, $names)), ...$names]));
    }

    /**
     * Lignes du fichier (cellules en texte).
     *
     * @return list<list<mixed>>
     */
    private function read(string $path, string $extension): array
    {
        if ($extension === 'csv' || $extension === 'txt') {
            $handle = @fopen($path, 'r');
            $first = $handle !== false ? (string) fgets($handle) : '';
            if ($handle !== false) {
                fclose($handle);
            }
            $options = new CsvReaderOptions;
            $options->FIELD_DELIMITER = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
            $reader = new CsvReader($options);
        } else {
            $reader = new XlsxReader;
        }

        $rows = [];

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }
                break; // première feuille seulement
            }
            $reader->close();
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    private function csvWriterOptions(): CsvWriterOptions
    {
        $options = new CsvWriterOptions;
        $options->FIELD_DELIMITER = ';';

        return $options;
    }

    /** « Prix base » ou « Seuil de stock faible » : en-têtes tolérés (casse, accents, espaces). */
    private function column(string $header): string
    {
        $column = str_replace([' ', '-'], '_', CatalogService::normalize($header));

        return match ($column) {
            'seuil', 'seuil_stock', 'seuil_de_stock_faible' => 'seuil_stock_faible',
            'prix', 'prix_de_base' => 'prix_base',
            'categorie_produit', 'category' => 'categorie',
            'nom', 'article', 'produit_nom' => 'produit',
            default => $column,
        };
    }

    /**
     * @throws ImportRowException
     */
    private function number(string $value, string $column): ?float
    {
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", 'FCFA', 'F'], '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            throw new ImportRowException("{$column} invalide : « {$value} » (un nombre positif, sans devise).");
        }

        return (float) $normalized;
    }

    /**
     * @throws ImportRowException
     */
    private function integer(string $value, string $column): ?int
    {
        $number = $this->number($value, $column);

        if ($number !== null && floor($number) != $number) {
            throw new ImportRowException("{$column} invalide : « {$value} » (un nombre entier).");
        }

        return $number === null ? null : (int) $number;
    }

    private function boolean(string $value): ?bool
    {
        return match (CatalogService::normalize($value)) {
            'oui', 'o', '1', 'true', 'vrai', 'yes', 'actif' => true,
            'non', 'n', '0', 'false', 'faux', 'no', 'inactif' => false,
            default => null,
        };
    }
}
