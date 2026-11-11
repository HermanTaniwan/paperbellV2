<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PrinterUsageService.php';

function checkUsage(mixed $actual,mixed $expected,string $label): void {
    if ($actual!==$expected) throw new RuntimeException($label.': '.json_encode($actual));
}
$a5=array_fill(0,5,[148,210]);
$b5=array_fill(0,2,[182,257]);
checkUsage(PrinterUsageService::tracks('EPSON WF-C5390 Series'),true,'Windows printer');
checkUsage(PrinterUsageService::tracks('EPSON_WF_C5390_Series'),true,'Printer alias');
checkUsage(PrinterUsageService::tracks('WF5390'),true,'Short model name');
checkUsage(PrinterUsageService::tracks('EPSON WF-C5790 Series'),false,'Exclude 5790');
checkUsage(PrinterUsageService::countSides('1-,duplexlong,paperkind=13',1,$b5),['a5'=>0,'b5'=>2,'unresolved'=>0],'B5 duplex is two sides / four points');
checkUsage(PrinterUsageService::countSides('1-,duplexlong,paper=A5,3x',3,$a5),['a5'=>15,'b5'=>0,'unresolved'=>0],'Copies once, no duplex multiplier');
checkUsage(PrinterUsageService::countSides('2-5,odd,paper=A5',2,$a5),['a5'=>4,'b5'=>0,'unresolved'=>0],'Absolute odd pages in range');
checkUsage(PrinterUsageService::countSides('2-,even,paper=A5',2,$a5),['a5'=>4,'b5'=>0,'unresolved'=>0],'Open range and even pages');
checkUsage(PrinterUsageService::countSides('3,simplex,paper=A5',4,$a5),['a5'=>4,'b5'=>0,'unresolved'=>0],'Single page');
checkUsage(PrinterUsageService::countSides('4-99,paper=A5',1,$a5),['a5'=>2,'b5'=>0,'unresolved'=>0],'Clamp range to PDF');
checkUsage(PrinterUsageService::countSides('1-,simplex',1,[[210,148],[250,176],[182,257],[210,297]]),['a5'=>1,'b5'=>2,'unresolved'=>1],'Detect size and flag unknown');
checkUsage(PrinterUsageService::countSides('1-,paper=A4',1,$a5),['a5'=>0,'b5'=>0,'unresolved'=>0],'Explicit other paper excluded');
checkUsage(PrinterUsageService::countSides('9-,paper=A5',1,$a5),['a5'=>0,'b5'=>0,'unresolved'=>0],'Empty selection');
$service=(new ReflectionClass(PrinterUsageService::class))->newInstanceWithoutConstructor();
$format=new ReflectionMethod(PrinterUsageService::class,'format');
$base=['id'=>1,'a5'=>0,'b5'=>2,'point_limit'=>8,'unresolved_jobs'=>0,'started_at'=>time(),'ended_at'=>null];
$row=$format->invoke($service,$base);
checkUsage($row['points'],4,'Weighted points');checkUsage($row['needs_check'],true,'Exactly 50 percent');
$base['point_limit']=9;checkUsage($format->invoke($service,$base)['needs_check'],false,'Below 50 percent');
$base['point_limit']=0;$row=$format->invoke($service,$base);
checkUsage($row['needs_check'],false,'No limit configured');checkUsage($row['percent'],null,'Unknown capacity');
echo "Printer usage formula tests passed\n";
