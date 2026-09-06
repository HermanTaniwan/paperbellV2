<?php
declare(strict_types=1);

/** Fixed, Windows-only health collection; no request input reaches PowerShell. */
final class ServerHealthService
{
    public function __construct(private readonly array $config, private readonly string $root) {}
    public function overview(): array {
        $path=$this->root.'/storage/cache/server-health.json';
        $cache=$this->read($path);
        $now=time();
        $lastAttempt=(int)($cache['attempted_at']??$cache['checked_at']??0);
        $cacheSeconds=max(10,(int)($this->config['cache_seconds']??60));

        // The scheduled task normally keeps this cache warm. Refreshing here as a
        // fallback makes the page self-healing when that task has not been installed.
        $canRefresh=PHP_OS_FAMILY==='Windows'||(PHP_OS_FAMILY==='Linux'&&is_readable('/proc/stat'));
        if($canRefresh&&($lastAttempt===0||$now-$lastAttempt>=$cacheSeconds)){
            $lock=$this->lock($path.'.lock');
            if($lock!==null){
                try{
                    // Another request may have refreshed the cache before this lock.
                    $cache=$this->read($path);
                    $lastAttempt=(int)($cache['attempted_at']??$cache['checked_at']??0);
                    if($lastAttempt===0||$now-$lastAttempt>=$cacheSeconds){
                        try{
                            $cache=$this->refresh();
                        }catch(Throwable $error){
                            $cache['attempted_at']=$now;
                            $cache['collector_error']='Collector gagal: '.$error->getMessage();
                            $this->write($path,$cache);
                        }
                    }
                }finally{
                    flock($lock,LOCK_UN);
                    fclose($lock);
                }
            }
        }

        if($cache===[])$cache=['checked_at'=>0,'collector_error'=>'Monitoring belum menghasilkan data.'];
        return $this->status($cache,$now);
    }
    public function refresh(): array {
        $now=time();$data=$this->collect();$data['checked_at']=$now;$data['collector_error']=null;
        $this->write($this->root.'/storage/cache/server-health.json',$data);
        return $this->status($data,$now);
    }
    private function collect(): array {
        if(PHP_OS_FAMILY==='Linux')return $this->collectLinux();
        if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Server Health hanya mendukung host Windows dan Linux.');
        $libraryPath=(string)($this->config['librehardwaremonitor_library']??'');$script='$libraryPath='.json_encode($libraryPath,JSON_UNESCAPED_SLASHES).";\n". <<<'PS'
$ErrorActionPreference='Stop'
$cpu=Get-CimInstance Win32_Processor|Measure-Object -Property LoadPercentage -Average
$os=Get-CimInstance Win32_OperatingSystem
$disks=Get-CimInstance Win32_LogicalDisk -Filter 'DriveType = 3'|ForEach-Object {[pscustomobject]@{letter=$_.DeviceID;total_bytes=[int64]$_.Size;free_bytes=[int64]$_.FreeSpace;used_bytes=[int64]$_.Size-[int64]$_.FreeSpace;usage_percent=if($_.Size){[math]::Round((1-($_.FreeSpace/$_.Size))*100,1)}else{$null}}}
$temperature=$null;try{$thermal=Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature -ErrorAction Stop|Select-Object -First 1;if($thermal -and $thermal.CurrentTemperature){$temperature=[math]::Round(($thermal.CurrentTemperature/10)-273.15,1)}}catch{}
$physical=@();try{$physical=Get-PhysicalDisk -ErrorAction Stop|ForEach-Object {[pscustomobject]@{name=$_.FriendlyName;health=if($_.HealthStatus){$_.HealthStatus.ToString()}else{$null};operational_status=if($_.OperationalStatus){($_.OperationalStatus -join ', ')}else{$null};temperature=$null}}}catch{}
$sensorSource='WMI/ACPI'
if($libraryPath -and (Test-Path -LiteralPath $libraryPath)){try{
  Add-Type -Path $libraryPath -ErrorAction Stop
  $computer=New-Object LibreHardwareMonitor.Hardware.Computer
  $computer.IsCpuEnabled=$true;$computer.IsStorageEnabled=$true;$computer.Open()
  $hardware=@();foreach($item in $computer.Hardware){$hardware+=$item;$hardware+=$item.SubHardware}
  foreach($item in $hardware){$item.Update();$temps=@($item.Sensors|Where-Object {$_.SensorType.ToString() -eq 'Temperature' -and $null -ne $_.Value});if(!$temps){continue};$value=[math]::Round((($temps|Measure-Object -Property Value -Maximum).Maximum),1);if($item.HardwareType.ToString() -eq 'Cpu'){$temperature=$value}else{foreach($disk in $physical){if($item.Name -and ($disk.name -like "*$($item.Name)*" -or $item.Name -like "*$($disk.name)*")){$disk.temperature=$value}}}}
  $computer.Close();$sensorSource='LibreHardwareMonitor'
}catch{}}
[pscustomobject]@{platform='Windows';cpu_percent=if($null -ne $cpu.Average){[math]::Round($cpu.Average,1)}else{$null};cpu_temperature=$temperature;temperature_source=$sensorSource;memory_total_bytes=[int64]$os.TotalVisibleMemorySize*1KB;memory_free_bytes=[int64]$os.FreePhysicalMemory*1KB;hostname=$env:COMPUTERNAME;server_time=(Get-Date).ToString('o');uptime_seconds=[int64]((Get-Date)-$os.LastBootUpTime).TotalSeconds;disks=@($disks);physical_disks=@($physical)}|ConvertTo-Json -Depth 5 -Compress
PS;
        $pipes=[];$process=proc_open([(string)($this->config['powershell']??'powershell.exe'),'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-Command',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('PowerShell collector tidak dapat dimulai.');$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException(trim($stderr)?:'PowerShell collector gagal.');$data=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Hasil collector tidak valid.');$total=max(0,(int)($data['memory_total_bytes']??0));$free=max(0,(int)($data['memory_free_bytes']??0));$data['memory_used_bytes']=max(0,$total-$free);$data['memory_usage_percent']=$total?round((($total-$free)/$total)*100,1):null;return $data;
    }
    private function collectLinux(): array {
        $first=$this->linuxCpuTimes();
        usleep(200000);
        $second=$this->linuxCpuTimes();
        $totalDelta=max(0,$second['total']-$first['total']);
        $idleDelta=max(0,$second['idle']-$first['idle']);
        $cpuPercent=$totalDelta>0?round((1-($idleDelta/$totalDelta))*100,1):null;

        $memory=[];
        foreach(preg_split('/\R/',$this->linuxSystemFile('/proc/meminfo'),-1,PREG_SPLIT_NO_EMPTY)?:[] as $line){
            if(preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/',$line,$match))$memory[$match[1]]=(int)$match[2]*1024;
        }
        $memoryTotal=max(0,(int)($memory['MemTotal']??0));
        $memoryFree=max(0,(int)($memory['MemAvailable']??(($memory['MemFree']??0)+($memory['Buffers']??0)+($memory['Cached']??0))));
        $memoryUsed=max(0,$memoryTotal-$memoryFree);

        return [
            'platform'=>'Linux',
            'cpu_percent'=>$cpuPercent,
            'cpu_temperature'=>$this->linuxCpuTemperature(),
            'temperature_source'=>'Linux sysfs',
            'memory_total_bytes'=>$memoryTotal,
            'memory_free_bytes'=>$memoryFree,
            'memory_used_bytes'=>$memoryUsed,
            'memory_usage_percent'=>$memoryTotal?round(($memoryUsed/$memoryTotal)*100,1):null,
            'hostname'=>gethostname()?:php_uname('n'),
            'server_time'=>date('c'),
            'uptime_seconds'=>(int)(float)(explode(' ',trim($this->linuxSystemFile('/proc/uptime')))[0]??0),
            'disks'=>$this->linuxDisks(),
            'physical_disks'=>$this->linuxPhysicalDisks(),
        ];
    }
    private function linuxCpuTimes(): array {
        $line=strtok($this->linuxSystemFile('/proc/stat'),"\r\n")?:'';
        $values=array_map('intval',preg_split('/\s+/',trim(substr($line,3)))?:[]);
        if(count($values)<4)throw new RuntimeException('Statistik CPU Linux tidak dapat dibaca.');
        return ['idle'=>($values[3]??0)+($values[4]??0),'total'=>array_sum($values)];
    }
    private function linuxSystemFile(string $path): string {
        $contents=@file_get_contents($path);
        if($contents!==false)return $contents;
        return $this->linuxCommand(['/bin/cat','--',$path]);
    }
    private function linuxCommand(array $command): string {
        $pipes=[];
        $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Collector Linux tidak dapat dimulai.');
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException(trim($stderr)?:'Perintah collector Linux gagal.');
        return (string)$stdout;
    }
    private function linuxCpuTemperature(): ?float {
        $values=[];
        foreach(glob('/sys/class/hwmon/hwmon*/temp*_input')?:[] as $path){
            $labelPath=preg_replace('/_input$/','_label',$path);
            $label=strtolower(trim((string)@file_get_contents((string)$labelPath)));
            if($label!==''&&!preg_match('/cpu|package|core|tctl|tdie/',$label))continue;
            $value=(float)trim((string)@file_get_contents($path))/1000;
            if($value>0&&$value<130)$values[]=$value;
        }
        foreach(glob('/sys/class/thermal/thermal_zone*/temp')?:[] as $path){
            $type=strtolower(trim((string)@file_get_contents(dirname($path).'/type')));
            if($type!==''&&!preg_match('/cpu|x86_pkg|acpi/',$type))continue;
            $value=(float)trim((string)@file_get_contents($path))/1000;
            if($value>0&&$value<130)$values[]=$value;
        }
        return $values===[]?null:round(max($values),1);
    }
    private function linuxDisks(): array {
        $disks=[];
        $output=$this->linuxCommand(['/usr/bin/df','-B1','-P','-x','tmpfs','-x','devtmpfs']);
        foreach(array_slice(preg_split('/\R/',$output,-1,PREG_SPLIT_NO_EMPTY)?:[],1) as $line){
            $parts=preg_split('/\s+/',$line,6);
            if(count($parts)!==6)continue;
            [$device,$total,$used,$free,$percent,$mount]=$parts;
            if(!str_starts_with($device,'/dev/')||(int)$total<=0)continue;
            $disks[]=['letter'=>$mount,'device'=>$device,'total_bytes'=>(int)$total,'free_bytes'=>(int)$free,'used_bytes'=>(int)$used,'usage_percent'=>(float)rtrim($percent,'%')];
        }
        return $disks;
    }
    private function linuxPhysicalDisks(): array {
        $disks=[];
        foreach(glob('/sys/block/*',GLOB_ONLYDIR)?:[] as $path){
            $name=basename($path);
            if(preg_match('/^(loop|ram|zram|dm-)/',$name))continue;
            $model=trim((string)@file_get_contents($path.'/device/model'));
            $state=trim((string)@file_get_contents($path.'/device/state'));
            $disks[]=['name'=>$model!==''?$model:$name,'device'=>'/dev/'.$name,'health'=>null,'operational_status'=>$state!==''?$state:null,'temperature'=>null];
        }
        return $disks;
    }
    private function status(array $data,int $now): array {$t=$this->config['thresholds']??[];$age=($data['checked_at']??0)?max(0,$now-(int)$data['checked_at']):null;$status=$age===null||$age>(int)($t['offline_after_seconds']??300)?'offline':'healthy';foreach([['cpu_percent','cpu'],['memory_usage_percent','memory'],['cpu_temperature','cpu_temperature']]as[$key,$group]){$value=$data[$key]??null;if($value===null)continue;if($value>=($t[$group]['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t[$group]['warning']??INF))$status='warning';}foreach(($data['disks']??[])as$disk){$value=$disk['usage_percent']??null;if($value===null)continue;if($value>=($t['disk']['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t['disk']['warning']??INF))$status='warning';}foreach(($data['physical_disks']??[])as$disk){$value=$disk['temperature']??null;if($value===null)continue;if($value>=($t['ssd_temperature']['critical']??INF))$status='critical';elseif($status==='healthy'&&$value>=($t['ssd_temperature']['warning']??INF))$status='warning';}$data['status']=$status;$data['age_seconds']=$age;$data['thresholds']=$t;return $data;}
    private function read(string $path): array {try{$value=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];return is_array($value)?$value:[];}catch(Throwable){return[];}}
    /** @return resource|null */
    private function lock(string $path) {
        $dir=dirname($path);
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))return null;
        $handle=@fopen($path,'c');
        if($handle===false)return null;
        if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);return null;}
        return $handle;
    }
    private function write(string $path,array $data): void {$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cache monitoring tidak dapat dibuat.');$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cache monitoring tidak dapat disimpan.');}}
}
