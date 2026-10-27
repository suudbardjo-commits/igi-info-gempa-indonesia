<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExternalNews extends Model
{
    use HasUuids;

    protected $table = 'external_news';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'fetched_at' => 'datetime'];
    }

    /**
     * Yang masih layak ditampilkan. Tiap sumber punya masa tayang sendiri: peringatan cuaca basi dalam hitungan jam
     * dan menyesatkan bila dibiarkan, sedangkan berita BNPB tetap berguna berminggu-minggu.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (config('disaster.news.sources') as $key => $source) {
                $q->orWhere(fn (Builder $s) => $s->where('source', $key)->where('published_at', '>=', now()->subHours($source['retention_hours'])));
            }
        })->orderByDesc('published_at');
    }

    public function toPublicArray(): array
    {
        $source = config("disaster.news.sources.{$this->source}");

        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_label' => $source['label'] ?? strtoupper($this->source),
            'kind' => $source['kind'] ?? null,
            'title' => $this->title,
            'summary' => $this->summary,
            'url' => $this->url,
            'published_at' => $this->published_at->toIso8601String(),
        ];
    }
}
