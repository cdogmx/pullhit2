<?php
namespace App\Console\Commands;
use App\Models\ProductLine; use App\Models\Set; use App\Models\CatalogItem;
use Illuminate\Console\Command;
class TmpJpCount extends Command {
  protected $signature='tmp:jp-count';
  protected $description='count JP sets/items';
  public function handle():int {
    $pk=ProductLine::where('slug','pokemon')->first();
    $sets=Set::where('product_line_id',$pk->id)->where('language','ja')->count();
    $items=CatalogItem::where('product_line_id',$pk->id)->whereHas('set',fn($q)=>$q->where('language','ja'))->count();
    $this->line("JP sets: $sets, JP items: $items");
    // newest set
    $newest=Set::where('product_line_id',$pk->id)->where('language','ja')->latest('id')->first();
    if($newest){ $n=CatalogItem::where('set_id',$newest->id)->count(); $this->line("newest: {$newest->name} ({$n} items) gid=".json_encode($newest->getAttribute('external_ids')['tcgplayer_group_id']??null)); }
    return 0;
  }
}
