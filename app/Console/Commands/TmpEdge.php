<?php
namespace App\Console\Commands;
use App\Actions\Catalog\SearchCatalog;use App\Actions\Catalog\SuggestSearch;use Illuminate\Console\Command;
class TmpEdge extends Command{protected $signature='tmp:edge';
 public function handle(SuggestSearch $sug,SearchCatalog $search):int{
  $cases=['%','_','100%','a%b','\','"','\'','ex ex ex ex ex','Pokémon','日本語',str_repeat('a',300),'   ','#1','c/2','&amp;'];
  foreach($cases as $c){
   $label=strlen($c)>20?substr($c,0,17).'...':$c;
   try{$t=microtime(true);$sug($c);$m1=round((microtime(true)-$t)*1000);}catch(\Throwable $e){$m1='ERR:'.substr($e->getMessage(),0,60);}
   try{$t=microtime(true);$p=$search(['q'=>$c]);$m2=round((microtime(true)-$t)*1000).'ms/'.$p->total();}catch(\Throwable $e){$m2='ERR:'.substr($e->getMessage(),0,60);}
   $this->line(sprintf("  [%-20s] suggest=%-12s browse=%s",$label,is_string($m1)?$m1:$m1.'ms',$m2));
  }
  return 0;}}
