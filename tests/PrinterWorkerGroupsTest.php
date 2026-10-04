<?php
declare(strict_types=1);
define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY',true);
require dirname(__DIR__).'/worker/print-worker.php';
function checkGroup(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$testLocks=$root.'/storage/worker-locks/tests-'.bin2hex(random_bytes(4));
$brotherLocks=acquirePrintWorkerLocks($testLocks,'brother');
$labelLocks=acquirePrintWorkerLocks($testLocks,'l3210');
foreach(['all','brother'] as $blocked){
    $rejected=false;
    try{acquirePrintWorkerLocks($testLocks,$blocked);}catch(RuntimeException){$rejected=true;}
    checkGroup($rejected,'Duplicate/mixed worker mode was allowed');
}
foreach(array_merge($brotherLocks,$labelLocks) as $lock){flock($lock,LOCK_UN);fclose($lock);}
$allLocks=acquirePrintWorkerLocks($testLocks,'all');
$rejected=false;
try{acquirePrintWorkerLocks($testLocks,'wf5390');}catch(RuntimeException){$rejected=true;}
checkGroup($rejected,'Grouped worker overlapped legacy mode');
foreach($allLocks as $lock){flock($lock,LOCK_UN);fclose($lock);}
foreach(glob($testLocks.'/*.lock') as $file)unlink($file);
rmdir($testLocks);

$db=Database::mysql($config['mysql']);
// Connection-local temporary table shadows the production name; tests never
// modify the application's actual print_jobs table.
$db->exec("CREATE TEMPORARY TABLE print_jobs (id INT PRIMARY KEY,printer VARCHAR(200),status VARCHAR(30),message TEXT,error TEXT,started_at BIGINT NULL,completed_at BIGINT NULL,submitted_at BIGINT NULL,spooler_job_id INT NULL)");
$insert=$db->prepare("INSERT INTO print_jobs(id,printer,status,message,error) VALUES(?,?,?,'Menyiapkan dokumen','')");
for($i=1;$i<=30;$i++)$insert->execute([$i,'EPSON WF-C5390 Series','queued']);
$insert->execute([100,'Brother DCP-T830DW Printer','queued']);
$insert->execute([101,'EPSON L3210 Series','processing']);
$insert->execute([102,'EPSONA90DDD (WF-C5790 Series)','processing']);
$insert->execute([103,'WF-C5790 Series(Network)','processing']);
$insert->execute([104,'Future printer','queued']);
$ids=$db->query("SELECT id FROM print_jobs WHERE status='queued'".printerGroupSql('brother').' ORDER BY id LIMIT 25')->fetchAll(PDO::FETCH_COLUMN);
checkGroup(array_map('intval',$ids)===[100],'Another printer backlog blocked Brother');
$printerGroup='wf5790';
recoverInterruptedJobs($db);
checkGroup((int)$db->query("SELECT COUNT(*) FROM print_jobs WHERE id IN (102,103) AND status='queued'")->fetchColumn()===2,'WF-5790 aliases not recovered together');
checkGroup($db->query('SELECT status FROM print_jobs WHERE id=101')->fetchColumn()==='processing','Startup recovery touched another running printer');
checkGroup((int)$db->query("SELECT id FROM print_jobs WHERE status='queued'".printerGroupSql('other'))->fetchColumn()===104,'Other printers were excluded');
$db->exec('DROP TEMPORARY TABLE print_jobs');
echo "Printer groups isolate backlog/recovery and prevent duplicate or mixed worker modes\n";
