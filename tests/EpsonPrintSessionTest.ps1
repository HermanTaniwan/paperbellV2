param([string]$PrinterName='WF-C5790 Series(Network)')
$ErrorActionPreference='Stop'
$runner=Join-Path $PSScriptRoot '../tools/print-epson-pdf.ps1'
$backup=Join-Path $PSScriptRoot '../storage/print-labels/epson-session-test.bin'
$options=@{PrinterName=$PrinterName;Paper='A5';BackupPath=$backup;Settings='3-4,paper=A5,bin=7,noscale,duplexlong,20x';PdfPath='unused.pdf';Sumatra='missing-sumatra.exe';Duplex='duplexlong';InputBin=7}
$result=(& $runner @options -ValidateOnly)|ConvertFrom-Json
if($result.submitted -or $result.paperkind -ne 11 -or $result.input_bin -ne 7 -or $result.duplex -ne 2 -or $result.width_mm -ne 148 -or $result.height_mm -ne 210 -or $result.restore_error){throw 'A5/Auto Select/duplex validation failed'}
if($result.settings -ne $options.Settings){throw 'Sumatra settings differ from the validated command'}
if(Test-Path -LiteralPath $backup){throw 'Successful restore left its backup'}
$failed=$false
try{& $runner @options|Out-Null}catch{if($_.Exception.Message -notmatch 'SumatraPDF not found'){throw};$failed=$true}
if(-not $failed -or (Test-Path -LiteralPath $backup)){throw 'Submission failure did not restore driver settings'}
"Validated A5, Auto Select, duplex, normal restore and failure restore without printing. Native apply=$($result.apply_ms)ms; restore=$($result.restore_ms)ms"
