$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:PHP_BIN) { $env:PHP_BIN } else { 'php' }
$procs = @()
$global:daemonPids = @()
$global:daemonScriptNames = @(
    'admin_daemon.php',
    'binkp_scheduler.php',
    'binkp_server.php'
)
$script:stopping = $false
$jobHandle = $null

Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class JobApi {
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
    public static extern IntPtr CreateJobObject(IntPtr lpJobAttributes, string lpName);

    [DllImport("kernel32.dll")]
    public static extern bool AssignProcessToJobObject(IntPtr hJob, IntPtr hProcess);

    [DllImport("kernel32.dll", SetLastError = true)]
    public static extern bool SetInformationJobObject(IntPtr hJob, int JobObjectInfoClass, IntPtr lpJobObjectInfo, uint cbJobObjectInfoLength);

    [DllImport("kernel32.dll", SetLastError = true)]
    public static extern bool CloseHandle(IntPtr hObject);
}

[StructLayout(LayoutKind.Sequential)]
public struct JOBOBJECT_BASIC_LIMIT_INFORMATION {
    public Int64 PerProcessUserTimeLimit;
    public Int64 PerJobUserTimeLimit;
    public UInt32 LimitFlags;
    public UIntPtr MinimumWorkingSetSize;
    public UIntPtr MaximumWorkingSetSize;
    public UInt32 ActiveProcessLimit;
    public IntPtr Affinity;
    public UInt32 PriorityClass;
    public UInt32 SchedulingClass;
}

[StructLayout(LayoutKind.Sequential)]
public struct IO_COUNTERS {
    public UInt64 ReadOperationCount;
    public UInt64 WriteOperationCount;
    public UInt64 OtherOperationCount;
    public UInt64 ReadTransferCount;
    public UInt64 WriteTransferCount;
    public UInt64 OtherTransferCount;
}

[StructLayout(LayoutKind.Sequential)]
public struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION {
    public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
    public IO_COUNTERS IoInfo;
    public UIntPtr ProcessMemoryLimit;
    public UIntPtr JobMemoryLimit;
    public UIntPtr PeakProcessMemoryUsed;
    public UIntPtr PeakJobMemoryUsed;
}
"@

function Initialize-Job {
    $script:jobHandle = [JobApi]::CreateJobObject([IntPtr]::Zero, 'BinktermPHPDaemonJob')
    if ($script:jobHandle -eq [IntPtr]::Zero) {
        return
    }

    $limitInfo = New-Object JOBOBJECT_EXTENDED_LIMIT_INFORMATION
    $limitInfo.BasicLimitInformation.LimitFlags = 0x00002000 # JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
    $size = [System.Runtime.InteropServices.Marshal]::SizeOf($limitInfo)
    $ptr = [System.Runtime.InteropServices.Marshal]::AllocHGlobal($size)
    try {
        [System.Runtime.InteropServices.Marshal]::StructureToPtr($limitInfo, $ptr, $false)
        [JobApi]::SetInformationJobObject($script:jobHandle, 9, $ptr, [uint32]$size) | Out-Null
    } finally {
        [System.Runtime.InteropServices.Marshal]::FreeHGlobal($ptr)
    }
}

function Start-Daemon {
    param(
        [string]$Script,
        [string]$Name
    )
    $path = Join-Path $root $Script
    $proc = Start-Process -FilePath $php -ArgumentList $path -WorkingDirectory $root -PassThru -WindowStyle Normal
    $script:procs += $proc
    $global:daemonPids += $proc.Id
    if ($script:jobHandle -ne $null -and $script:jobHandle -ne [IntPtr]::Zero) {
        [JobApi]::AssignProcessToJobObject($script:jobHandle, $proc.Handle) | Out-Null
    }
    Write-Host ("Started {0} PID {1}" -f $Name, $proc.Id)
}

function global:Stop-Daemons {
    Write-Host 'Stopping daemons...'
    if ($script:jobHandle -ne $null -and $script:jobHandle -ne [IntPtr]::Zero) {
        [JobApi]::CloseHandle($script:jobHandle) | Out-Null
        $script:jobHandle = [IntPtr]::Zero
    }
    foreach ($proc in $procs) {
        try {
            Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
        } catch {
        }
    }

    foreach ($pid in $global:daemonPids) {
        try {
            Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
        } catch {
        }
    }

    foreach ($name in $global:daemonScriptNames) {
        try {
            Get-CimInstance Win32_Process |
                Where-Object { $_.CommandLine -and $_.CommandLine -like "*\\scripts\\$name*" } |
                ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        } catch {
        }
    }
}

Start-Daemon 'scripts/admin_daemon.php' 'admin_daemon'
Start-Daemon 'scripts/binkp_scheduler.php' 'binkp_scheduler'
Start-Daemon 'scripts/binkp_server.php' 'binkp_server'
Write-Host 'Press Ctrl+C to stop daemons.'

try {
    Initialize-Job
    while ($true) {
        Start-Sleep -Seconds 1
    }
} catch [System.Management.Automation.Host.ControlCException] {
    Stop-Daemons
} catch [System.Management.Automation.PipelineStoppedException] {
    Stop-Daemons
} finally {
    Stop-Daemons
}
