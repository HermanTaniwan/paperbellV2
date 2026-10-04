param(
 [Parameter(Mandatory=$true)][string]$PrinterName,
 [Parameter(Mandatory=$true)][string]$Paper,
 [Parameter(Mandatory=$true)][string]$BackupPath,
 [Parameter(Mandatory=$true)][string]$Settings,
 [Parameter(Mandatory=$true)][string]$PdfPath,
 [Parameter(Mandatory=$true)][string]$Sumatra,
 [ValidateSet('simplex','duplexlong','duplexshort')][string]$Duplex='simplex',
 [int]$InputBin=7,
 [switch]$ValidateOnly
)
$ErrorActionPreference='Stop'
$helper=Join-Path $PSScriptRoot 'set-epson-pdf-paper.ps1'
$native=$null;$submitMs=0;$restoreMs=0;$submitted=$false
$timer=[Diagnostics.Stopwatch]::StartNew()
try {
 $native=(& $helper -PrinterName $PrinterName -Paper $Paper -BackupPath $BackupPath -Duplex $Duplex -InputBin $InputBin)|ConvertFrom-Json
 $applyMs=$timer.ElapsedMilliseconds
 $settingsToUse=$Settings
 if(-not $ValidateOnly){
  if(-not (Test-Path -LiteralPath $Sumatra -PathType Leaf)){throw 'SumatraPDF not found'}
  if(-not (Test-Path -LiteralPath $PdfPath -PathType Leaf)){throw 'PDF not found'}
  $timer.Restart()
  # Invocation passes each value as an argument and waits for submission.
  & $Sumatra '-print-to' $PrinterName '-print-settings' $settingsToUse '-silent' $PdfPath | Out-Null
  if($LASTEXITCODE -ne 0){throw "SumatraPDF failed (exit $LASTEXITCODE)"}
  $submitMs=$timer.ElapsedMilliseconds
  $submitted=$true
 }
}finally{
 if($null -ne $native){
  $timer.Restart()
  try {
   & $helper -PrinterName $PrinterName -BackupPath $BackupPath -Restore | Out-Null
   Remove-Item -LiteralPath $BackupPath
  }catch{
   # Submission succeeded: preserve that fact and leave the backup for recovery.
   # A restore error must never turn a sent job into a retryable failure.
   if(-not $submitted){throw}
   $restoreError=$_.Exception.Message
  }
  $restoreMs=$timer.ElapsedMilliseconds
 }
}
@{paperkind=$native.paperkind;input_bin=$native.input_bin;duplex=$native.duplex;width_mm=$native.width_mm;height_mm=$native.height_mm;settings=$settingsToUse;apply_ms=$applyMs;submit_ms=$submitMs;restore_ms=$restoreMs;submitted=$submitted;restore_error=$restoreError}|ConvertTo-Json -Compress
