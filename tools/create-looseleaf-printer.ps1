param(
    [string]$SourcePrinter = 'Brother DCP-T830DW Printer',
    [string]$ProfileName = 'Brother A5 Tray bawah'
)
$ErrorActionPreference = 'Stop'
if ($SourcePrinter -eq $ProfileName) { throw 'The profile must use a separate printer queue.' }
$source = Get-Printer -Name $SourcePrinter -ErrorAction Stop
if ($source.DriverName -notlike '*Brother*') { throw 'The source must use a Brother driver.' }
$existing = Get-Printer -Name $ProfileName -ErrorAction SilentlyContinue
if ($existing -and ($existing.DriverName -ne $source.DriverName -or $existing.PortName -ne $source.PortName)) {
    throw 'An unrelated printer already uses the profile name.'
}
if ($existing -and @(Get-PrintJob -PrinterName $ProfileName -ErrorAction Stop).Count) {
    throw 'Wait until the profile queue is empty before changing its defaults.'
}

# Clone the regular Brother defaults, including its private driver settings.
# Do not inherit the borderless/photo settings of the cover or swatch queues.
[xml]$ticket = (Get-PrintConfiguration -PrinterName $SourcePrinter).PrintTicketXML
$ns = [Xml.XmlNamespaceManager]::new($ticket.NameTable)
$ns.AddNamespace('psf', 'http://schemas.microsoft.com/windows/2003/08/printing/printschemaframework')
if (-not $ticket.DocumentElement.GetNamespaceOfPrefix('brpsk')) { throw 'Brother PrintTicket namespace is missing.' }
function Set-ProfileOption([string]$featureName, [string]$optionName) {
    $feature = $ticket.SelectSingleNode("//psf:Feature[@name='$featureName']", $ns)
    if ($null -eq $feature) { throw "Driver does not expose $featureName" }
    foreach ($option in @($feature.SelectNodes('psf:Option', $ns))) { [void]$feature.RemoveChild($option) }
    $option = $ticket.CreateElement('psf', 'Option', $ns.LookupNamespace('psf'))
    $option.SetAttribute('name', $optionName)
    [void]$feature.AppendChild($option)
}
Set-ProfileOption 'psk:PageMediaSize' 'psk:ISOA5'
Set-ProfileOption 'psk:PageMediaType' 'psk:Plain'
Set-ProfileOption 'psk:PageOutputQuality' 'psk:Normal'
Set-ProfileOption 'psk:JobDuplexAllDocumentsContiguously' 'psk:TwoSidedLongEdge'
Set-ProfileOption 'psk:PageInputBin' 'brpsk:Tray1'

if (-not $existing) {
    Add-Printer -Name $ProfileName -DriverName $source.DriverName -PortName $source.PortName
}
Set-PrintConfiguration -PrinterName $ProfileName -PrintTicketXml $ticket.OuterXml
# Use the typed paper setter to fill Brother's media dimension properties.
Set-PrintConfiguration -PrinterName $ProfileName -PaperSize A5

# Set both queue-wide and current-user defaults; Sumatra reads the user DEVMODE.
Add-Type -AssemblyName ReachFramework, System.Printing
$server = [System.Printing.LocalPrintServer]::new()
$queue = $server.GetPrintQueue($ProfileName)
try {
    $queue.UserPrintTicket = $queue.DefaultPrintTicket.Clone()
    $queue.Commit()
} finally {
    $queue.Dispose()
    $server.Dispose()
}
Add-Type -AssemblyName System.Drawing
$settings = [Drawing.Printing.PrinterSettings]::new()
$settings.PrinterName = $ProfileName
$page = $settings.DefaultPageSettings
if ($page.PaperSize.RawKind -ne 11 -or $page.PaperSource.RawKind -ne 1 -or $settings.Duplex -ne [Drawing.Printing.Duplex]::Vertical) {
    throw "Driver rejected profile defaults: paper=$($page.PaperSize.RawKind), tray=$($page.PaperSource.RawKind), duplex=$($settings.Duplex)"
}
[pscustomobject]@{
    name = $ProfileName
    driver = $source.DriverName
    port = $source.PortName
    paper = $page.PaperSize.PaperName
    tray = $page.PaperSource.SourceName
    duplex = $settings.Duplex.ToString()
} | ConvertTo-Json -Compress
