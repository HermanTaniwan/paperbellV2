<?php
declare(strict_types=1);

/** Fixed, Windows-only health collection; no request input reaches PowerShell. */
final class ServerHealthService
{
    public function __construct(private readonly array $config, private readonly string $root) {}
    public function overview(): array {
        $path=$this->root.'/storage/cache/server-health.json'; $cache=$this->read($path); $now=time(); $interval=max(15,(int)($this->config['cache_seconds']??60));
        if(($cache['checked_at']??0)+$interval>$now)return $this->status($cache,$now);
        try{$data=$this->collect();$data['checked_at']=$now;$data['collector_error']=null;$this->write($path,$data);return $this->status($data,$now);}catch(Throwable $e){error_log('Paperbell server-health collector: '.$e->getMessage());if($cache!==[]){$cache['collector_error']='Pembaruan metrik terakhir gagal; data cache ditampilkan.';return $this->status($cache,$now);}return $this->status(['checked_at'=>0,'collector_error'=>'Monitoring belum dapat dijalankan.'],$now);}
    }
    private function collect(): array {
        if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Server Health hanya dapat dikumpulkan pada host Windows.');
        $script= <<<'PS'
$ErrorActionPreference='Stop'
$cpu=Get-CimInstance Win32_Processor|Measure-Object -Property LoadPercentage -Average
$os=Get-CimInstance Win32_OperatingSystem
$disks=Get-CimInstance Win32_LogicalDisk -Filter 'DriveType = 3'|ForEach-Object {[pscustomobject]@{letter=$_.DeviceID;total_bytes=[int64]$_.Size;free_bytes=[int64]$_.FreeSpace;used_bytes=[int64]$_.Size-[int64]$_.FreeSpace;usage_percent=if($_.Size){[math]::Round((1-($_.FreeSpace/$_.Size))*100,1)}else{$null}}}
$temperature=$null;try{$thermal=Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature -ErrorAction Stop|Select-Object -First 1;if($thermal -and $thermal.CurrentTemperature){$temperature=[math]::Round(($thermal.CurrentTemperature/10)-273.15,1)}}catch{}
$physical=@();try{$physical=Get-PhysicalDisk -ErrorAction Stop|ForEach-Object {[pscustomobject]@{name=$_.FriendlyName;health=if($_.HealthStatus){$_.HealthStatus.ToString()}else{$null};operational_status=if($_.OperationalStatus){($_.OperationalStatus -join ', ')}else{$null};temperature=$null}}}catch{}
[pscustomobject]@{cpu_percent=if($null -ne $cpu.Average){[math]::Round($cpu.Average,1)}else{$null};cpu_temperature=$temperature;memory_total_bytes=[int64]$os.TotalVisibleMemorySize*1KB;memory_free_bytes=[int64]$os.FreePhysicalMemory*1KB;hostname=$env:COMPUTERNAME;server_time=(Get-Date).ToString('o');uptime_seconds=[int64]((Get-Date)-$os.LastBootUpTime).TotalSeconds;disks=@($disks);physical_disks=@($physical)}|ConvertTo-Json -Depth 5 -Compress
PS;
        $pipes=[];$process=proc_open([(string)($this->config['powershell']??'powershell.exe'),'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-Command',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('PowerShell collector tidak dapat dimulai.');$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException(trim($stderr)?:'PowerShell collector gagal.');$data=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Hasil collector tidak valid.');$total=max(0,(int)($data['memory_total_bytes']??0));$free=max(0,(int)($data['memory_free_bytes']??0));$data['memory_used_bytes']=max(0,$total-$free);$data['memory_usage_percent']=$total?round((($total-$free)/$total)*100,1):null;return $data;
    }
    private function status(array $data,int $now): array {$t=$this->config['thresholds']??[];$age=($data['checked_at']??0)?max(0,$now-(int)$data['checked_at']):null;$status=$age===null||$age>(int)($t['offline_after_seconds']??300)?'offline':'healthy';foreach([['cpu_percent','cpu'],['memory_usage_percent','memory'],['cpu_temperature','cpu_temperature']]as[$key,$group]){$value=$data[$key]??null;if($value===null)continue;if($value>=($t[$group]['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t[$group]['warning']??INF))$status='warning';}foreach(($data['disks']??[])as$disk){$value=$disk['usage_percent']??null;if($value===null)continue;if($value>=($t['disk']['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t['disk']['warning']??INF))$status='warning';}foreach(($data['physical_disks']??[])as$disk){$value=$disk['temperature']??null;if($value===null)continue;if($value>=($t['ssd_temperature']['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t['ssd_temperature']['warning']??INF))$status='warning';}$data['status']=$status;$data['age_seconds']=$age;$data['thresholds']=$t;return $data;}
    private function read(string $path): array {try{$value=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];return is_array($value)?$value:[];}catch(Throwable){return[];}}
    private function write(string $path,array $data): void {$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cache monitoring tidak dapat dibuat.');$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cache monitoring tidak dapat disimpan.');}}
}
