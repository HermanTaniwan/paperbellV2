param(
 [Parameter(Mandatory=$true)][string]$PrinterName,
 [ValidateSet('A4','A5','A6','B5','Letter')][string]$Paper,
 [Parameter(Mandatory=$true)][string]$BackupPath,
 [ValidateSet('simplex','duplexlong','duplexshort')][string]$Duplex='simplex',
 [int]$InputBin=7,
 [switch]$Restore
)
$ErrorActionPreference='Stop'
Add-Type @'
using System;
using System.Runtime.InteropServices;
public class EpsonPdfDevMode {
 [DllImport("winspool.drv", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool OpenPrinter(string name,out IntPtr handle,IntPtr defaults);
 [DllImport("winspool.drv", CharSet=CharSet.Unicode)] public static extern int DocumentProperties(IntPtr window,IntPtr handle,string name,IntPtr output,IntPtr input,int mode);
 [DllImport("winspool.drv")] public static extern bool ClosePrinter(IntPtr handle);
 [DllImport("winspool.drv", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool SetPrinter(IntPtr handle,int level,IntPtr info,int command);
}
'@
$dimensions=@{A4=@(2100,2970);A5=@(1480,2100);A6=@(1050,1480);B5=@(1820,2570);Letter=@(2159,2794)}
$handle=[IntPtr]::Zero; $inputBuffer=[IntPtr]::Zero; $outputBuffer=[IntPtr]::Zero; $info=[IntPtr]::Zero
$originalRaw=$null
try {
 if(-not [EpsonPdfDevMode]::OpenPrinter($PrinterName,[ref]$handle,[IntPtr]::Zero)){throw 'OpenPrinter failed'}
 $size=[EpsonPdfDevMode]::DocumentProperties([IntPtr]::Zero,$handle,$PrinterName,[IntPtr]::Zero,[IntPtr]::Zero,0)
 if($size -lt 220){throw 'Invalid driver DEVMODE size'}
 $inputBuffer=[Runtime.InteropServices.Marshal]::AllocHGlobal($size)
 $outputBuffer=[Runtime.InteropServices.Marshal]::AllocHGlobal($size)
 $info=[Runtime.InteropServices.Marshal]::AllocHGlobal([IntPtr]::Size)
 if($Restore){
  $raw=[IO.File]::ReadAllBytes($BackupPath)
  if($raw.Length -ne $size){throw 'Driver changed since the saved DEVMODE'}
  [Runtime.InteropServices.Marshal]::Copy($raw,0,$outputBuffer,$size)
  [Runtime.InteropServices.Marshal]::WriteIntPtr($info,$outputBuffer)
  if(-not [EpsonPdfDevMode]::SetPrinter($handle,9,$info,0)){throw 'Cannot restore user driver settings'}
  [EpsonPdfDevMode]::ClosePrinter($handle)|Out-Null
  $handle=[IntPtr]::Zero
  if(-not [EpsonPdfDevMode]::OpenPrinter($PrinterName,[ref]$handle,[IntPtr]::Zero)){throw 'Cannot reopen restored printer'}
  if([EpsonPdfDevMode]::DocumentProperties([IntPtr]::Zero,$handle,$PrinterName,$inputBuffer,[IntPtr]::Zero,2) -ne 1){throw 'Cannot verify restored driver'}
  foreach($offset in @(78,80,82,88,94)){
   if([Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,$offset) -ne [BitConverter]::ToInt16($raw,$offset)){throw 'Native paper settings did not restore'}
  }
  @{restored=$true}|ConvertTo-Json -Compress
  return
 }
 if(-not $Paper){throw 'Paper is required when applying settings'}
 $width,$height=$dimensions[$Paper]
 Add-Type -AssemblyName System.Drawing
 $printerSettings=New-Object Drawing.Printing.PrinterSettings
 $printerSettings.PrinterName=$PrinterName
 $supported=$printerSettings.PaperSizes|Where-Object { [Math]::Abs($_.Width*2.54-$width) -le 10 -and [Math]::Abs($_.Height*2.54-$height) -le 10 }|Select-Object -First 1
 $kind=256
 if($null -ne $supported){$kind=$supported.RawKind}
 if($InputBin -eq 258){
  $cassette=$printerSettings.PaperSources|Where-Object {$_.SourceName -match '(?i)(?:Cassette|Tray)\s*1'}|Select-Object -First 1
  if($null -eq $cassette){throw 'Cassette 1 is not reported by this driver'}
  $InputBin=$cassette.RawKind
 }
 $duplexKind=@{simplex=1;duplexlong=2;duplexshort=3}[$Duplex]
 if([EpsonPdfDevMode]::DocumentProperties([IntPtr]::Zero,$handle,$PrinterName,$inputBuffer,[IntPtr]::Zero,2) -ne 1){throw 'Cannot read driver DEVMODE'}
 $originalRaw=New-Object byte[] $size
 [Runtime.InteropServices.Marshal]::Copy($inputBuffer,$originalRaw,0,$size)
 [IO.File]::WriteAllBytes($BackupPath,$originalRaw)
 $fields=[Runtime.InteropServices.Marshal]::ReadInt32($inputBuffer,72)
 [Runtime.InteropServices.Marshal]::WriteInt32($inputBuffer,72,($fields -bor 14 -bor 512 -bor 4096))
 [Runtime.InteropServices.Marshal]::WriteInt16($inputBuffer,78,[int16]$kind)
 [Runtime.InteropServices.Marshal]::WriteInt16($inputBuffer,80,[int16]$height)
 [Runtime.InteropServices.Marshal]::WriteInt16($inputBuffer,82,[int16]$width)
 [Runtime.InteropServices.Marshal]::WriteInt16($inputBuffer,88,[int16]$InputBin)
 [Runtime.InteropServices.Marshal]::WriteInt16($inputBuffer,94,[int16]$duplexKind)
 if([EpsonPdfDevMode]::DocumentProperties([IntPtr]::Zero,$handle,$PrinterName,$outputBuffer,$inputBuffer,10) -ne 1){throw 'Driver validation failed'}
 if([Runtime.InteropServices.Marshal]::ReadInt16($outputBuffer,80) -ne $height -or [Runtime.InteropServices.Marshal]::ReadInt16($outputBuffer,82) -ne $width){throw "Driver rejected PDF dimensions for $Paper"}
 $validatedBin=[Runtime.InteropServices.Marshal]::ReadInt16($outputBuffer,88)
 $validatedDuplex=[Runtime.InteropServices.Marshal]::ReadInt16($outputBuffer,94)
 if($validatedBin -ne $InputBin -or $validatedDuplex -ne $duplexKind){throw "Driver '$PrinterName' rejected tray/duplex: requested tray=$InputBin duplex=$duplexKind; actual tray=$validatedBin duplex=$validatedDuplex"}
 [Runtime.InteropServices.Marshal]::WriteIntPtr($info,$outputBuffer)
 if(-not [EpsonPdfDevMode]::SetPrinter($handle,9,$info,0)){throw ('Cannot set user driver DEVMODE: '+[Runtime.InteropServices.Marshal]::GetLastWin32Error())}
 [EpsonPdfDevMode]::ClosePrinter($handle)|Out-Null
 $handle=[IntPtr]::Zero
 if(-not [EpsonPdfDevMode]::OpenPrinter($PrinterName,[ref]$handle,[IntPtr]::Zero)){throw 'Cannot reopen printer'}
 if([EpsonPdfDevMode]::DocumentProperties([IntPtr]::Zero,$handle,$PrinterName,$inputBuffer,[IntPtr]::Zero,2) -ne 1){throw 'Cannot read applied driver DEVMODE'}
 $actualWidth=[Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,82)
 $actualHeight=[Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,80)
 if($actualWidth -ne $width -or $actualHeight -ne $height -or [Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,78) -ne $kind){throw "Native driver rejected $width x $height (actual $actualWidth x $actualHeight)"}
 if([Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,88) -ne $InputBin -or [Runtime.InteropServices.Marshal]::ReadInt16($inputBuffer,94) -ne $duplexKind){throw 'Applied native tray or duplex setting is incorrect'}
 @{width_mm=$actualWidth/10;height_mm=$actualHeight/10;paperkind=$kind;input_bin=$InputBin;duplex=$duplexKind}|ConvertTo-Json -Compress
}catch{
 if($null -ne $originalRaw -and $handle -ne [IntPtr]::Zero){
  [Runtime.InteropServices.Marshal]::Copy($originalRaw,0,$outputBuffer,$originalRaw.Length)
  [Runtime.InteropServices.Marshal]::WriteIntPtr($info,$outputBuffer)
  [EpsonPdfDevMode]::SetPrinter($handle,9,$info,0)|Out-Null
 }
 throw
}finally{
 if($inputBuffer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::FreeHGlobal($inputBuffer)}
 if($outputBuffer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::FreeHGlobal($outputBuffer)}
 if($info -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::FreeHGlobal($info)}
 if($handle -ne [IntPtr]::Zero){[EpsonPdfDevMode]::ClosePrinter($handle)|Out-Null}
}
