<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Modèles en $fillable explicite « toutes les colonnes sauf id » (test de charge P8-03) : ils remplacent
 * $guarded = ['id'], qui interrogeait le schéma de la table à chaque requête. La liste doit rester égale aux colonnes
 * de la table : une migration qui ajoute une colonne doit aussi l'ajouter ici, sinon elle serait ignorée en silence.
 */
class ModelFillableTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'Toutes les colonnes sauf id';

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        $classes = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php')->contains(self::MARKER) as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = 'App\\'.$relative;
        }
        sort($classes);

        return $classes;
    }

    public function test_every_explicit_fillable_list_matches_the_table_columns(): void
    {
        $models = $this->models();
        $this->assertCount(21, $models, 'Modèles en $fillable explicite trouvés : '.implode(', ', $models));

        foreach ($models as $class) {
            $model = new $class;
            $columns = array_values(array_diff(Schema::getColumnListing($model->getTable()), ['id']));
            $fillable = $model->getFillable();
            sort($columns);
            sort($fillable);

            $this->assertSame($columns, $fillable, "{$class} : \$fillable doit lister toutes les colonnes de « {$model->getTable()} » sauf id.");
            $this->assertNotContains('id', $model->getFillable(), "{$class} : id ne doit jamais être assignable en masse.");
        }
    }
}
