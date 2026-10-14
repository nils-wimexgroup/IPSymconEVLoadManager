<?php

/**
 * Kleiner Symcon-Nachbau fuer Tests: Objekte, Variablen, Instanzen, Archiv und eine simulierte Uhr.
 * Die Module werden in den Namensraum Sim geladen, damit ihr Aufruf von time() die Simulationsuhr trifft.
 * Bildet nur das nach, was die EV-Module brauchen, und folgt dabei der Symcon-Doku.
 */

namespace {
    if (!defined('KR_READY')) {
        define('KR_READY', 10103);
        define('IPS_KERNELSTARTED', 10001);
    }
    $GLOBALS['SIM'] = [
        'now' => 0, 'next' => 10000, 'obj' => [], 'props' => [], 'attr' => [], 'mods' => [], 'status' => [],
        'logging' => [], 'aggtype' => [], 'logged' => [], 'viz' => [], 'log' => [], 'hooks' => [], 'profiles' => [],
        'kernel' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evsim_' . getmypid() . DIRECTORY_SEPARATOR
    ];
    @mkdir($GLOBALS['SIM']['kernel']);

    /** global sichtbare Modulfunktionen, wie Symcon sie bereitstellt */
    function EVAW_Ingest($id, $json)
    {
        return $GLOBALS['SIM']['mods'][$id]->Ingest($json);
    }
    function EVAW_Housekeeping($id)
    {
        return $GLOBALS['SIM']['mods'][$id]->Housekeeping();
    }
}

namespace Sim {
    use Exception as BaseException;

    class Exception extends BaseException
    {
    }

    function &S()
    {
        return $GLOBALS['SIM'];
    }
    function time()
    {
        return S()['now'];
    }
    /** HTTP-Kopfzeilen der WebHook-Antwort mitschreiben (auf der Kommandozeile gibt es keine) */
    function header($h)
    {
        S()['headers'][] = $h;
    }

    class IPSModule
    {
        public $InstanceID;

        public function __construct($id)
        {
            $this->InstanceID = $id;
        }
        public function Create()
        {
        }
        public function ApplyChanges()
        {
        }
        public function Destroy()
        {
        }
        protected function RegisterPropertyString($n, $d) { S()['props'][$this->InstanceID][$n] = S()['props'][$this->InstanceID][$n] ?? $d; }
        protected function RegisterPropertyInteger($n, $d) { $this->RegisterPropertyString($n, $d); }
        protected function RegisterPropertyFloat($n, $d) { $this->RegisterPropertyString($n, $d); }
        protected function RegisterPropertyBoolean($n, $d) { $this->RegisterPropertyString($n, $d); }
        protected function ReadPropertyString($n) { return (string) S()['props'][$this->InstanceID][$n]; }
        protected function ReadPropertyInteger($n) { return (int) S()['props'][$this->InstanceID][$n]; }
        protected function ReadPropertyFloat($n) { return (float) S()['props'][$this->InstanceID][$n]; }
        protected function ReadPropertyBoolean($n) { return (bool) S()['props'][$this->InstanceID][$n]; }
        protected function RegisterAttributeString($n, $d) { S()['attr'][$this->InstanceID][$n] = S()['attr'][$this->InstanceID][$n] ?? $d; }
        protected function RegisterAttributeInteger($n, $d) { $this->RegisterAttributeString($n, $d); }
        protected function RegisterAttributeBoolean($n, $d) { $this->RegisterAttributeString($n, $d); }
        protected function ReadAttributeString($n) { return (string) S()['attr'][$this->InstanceID][$n]; }
        protected function ReadAttributeInteger($n) { return (int) S()['attr'][$this->InstanceID][$n]; }
        protected function ReadAttributeBoolean($n) { return (bool) S()['attr'][$this->InstanceID][$n]; }
        protected function WriteAttributeString($n, $v) { S()['attr'][$this->InstanceID][$n] = (string) $v; }
        protected function WriteAttributeInteger($n, $v) { S()['attr'][$this->InstanceID][$n] = (int) $v; }
        protected function WriteAttributeBoolean($n, $v) { S()['attr'][$this->InstanceID][$n] = (bool) $v; }
        protected function RegisterTimer($n, $ms, $script) { }
        protected function SetTimerInterval($n, $ms) { S()['timer'][$this->InstanceID][$n] = $ms; }
        protected function SetStatus($s) { S()['status'][$this->InstanceID] = $s; }
        protected function GetStatus() { return S()['status'][$this->InstanceID] ?? 0; }
        protected function SetVisualizationType($t) { }
        protected function UpdateVisualizationValue($v) { S()['viz'][$this->InstanceID][] = $v; }
        protected function RegisterMessage($s, $m) { }
        protected function RegisterHook($h) { S()['hooks'][$h] = $this->InstanceID; }
        protected function SendDebug($a, $b, $c) { S()['log'][] = "DEBUG $a: $b"; }
    }

    // ---------------- Sperren (Symcon: IPS_SemaphoreEnter/Leave)
    function IPS_SemaphoreEnter($n, $ms) { if (!empty(S()['sem'][$n])) { return false; } S()['sem'][$n] = true; return true; }
    function IPS_SemaphoreLeave($n) { unset(S()['sem'][$n]); return true; }

    // ---------------- Objekte
    function newId() { return S()['next']++; }
    function IPS_GetKernelRunlevel() { return KR_READY; }
    function IPS_GetKernelDir() { return S()['kernel']; }
    function IPS_LogMessage($s, $m) { S()['log'][] = "$s: $m"; }
    function IPS_ObjectExists($id) { return isset(S()['obj'][$id]); }
    function IPS_InstanceExists($id) { return isset(S()['obj'][$id]) && S()['obj'][$id]['ObjectType'] === 1; }
    function IPS_VariableExists($id) { return isset(S()['obj'][$id]) && S()['obj'][$id]['ObjectType'] === 2; }
    function IPS_GetObject($id) { $o = S()['obj'][$id]; $o['HasChildren'] = count(IPS_GetChildrenIDs($id)) > 0; return $o; }
    function IPS_GetName($id) { return S()['obj'][$id]['ObjectName']; }
    function IPS_GetParent($id) { return S()['obj'][$id]['ParentID']; }
    function IPS_GetLocation($id) { $p = []; while (isset(S()['obj'][$id])) { array_unshift($p, IPS_GetName($id)); $id = IPS_GetParent($id); } return implode('\\', $p); }
    function IPS_GetChildrenIDs($id) { $c = []; foreach (S()['obj'] as $k => $o) { if ($o['ParentID'] === $id) { $c[] = $k; } } return $c; }
    function IPS_SetParent($id, $p) { S()['obj'][$id]['ParentID'] = $p; }
    function IPS_SetName($id, $n) { S()['obj'][$id]['ObjectName'] = $n; }
    function IPS_SetIdent($id, $i) { S()['obj'][$id]['ObjectIdent'] = $i; }
    function IPS_SetPosition($id, $p) { S()['obj'][$id]['ObjectPosition'] = $p; }
    function IPS_GetObjectIDByIdent($ident, $parent)
    {
        foreach (S()['obj'] as $k => $o) {
            if ($o['ParentID'] === $parent && ($o['ObjectIdent'] ?? '') === $ident) {
                return $k;
            }
        }
        trigger_error("Ident $ident nicht gefunden", E_USER_WARNING);
        return false;
    }
    function obj($type, $name, $parent) { $id = newId(); S()['obj'][$id] = ['ObjectType' => $type, 'ObjectName' => $name, 'ParentID' => $parent, 'ObjectIdent' => '']; return $id; }
    function IPS_CreateCategory() { return obj(0, 'Kategorie', 0); }
    function IPS_CreateVariable($type)
    {
        $id = obj(2, 'Variable', 0);
        S()['vars'][$id] = ['v' => [false, 0, 0.0, ''][$type], 'type' => $type, 'u' => S()['now'], 'c' => S()['now']];
        return $id;
    }
    function IPS_SetVariableCustomProfile($id, $p) { }
    function IPS_VariableProfileExists($p) { return isset(S()['profiles'][$p]); }
    function IPS_CreateVariableProfile($p, $t) { S()['profiles'][$p] = $t; }
    function IPS_SetVariableProfileDigits($p, $d) { }
    function IPS_SetVariableProfileText($p, $a, $b) { }
    function IPS_SetVariableProfileIcon($p, $i) { }
    function IPS_GetVariable($id) { $v = S()['vars'][$id]; return ['VariableType' => $v['type'], 'VariableUpdated' => $v['u'], 'VariableChanged' => $v['c']]; }
    function GetValue($id)
    {
        if (!IPS_VariableExists($id)) {
            throw new Exception("Variable $id existiert nicht");
        }
        return S()['vars'][$id]['v'];
    }
    function SetValue($id, $v)
    {
        $o = &S()['vars'][$id];
        $changed = $o['v'] !== $v;
        $o['v'] = $v;
        $o['u'] = S()['now'];
        if ($changed) {
            $o['c'] = S()['now'];
            if (!empty(S()['logging'][$id])) {
                S()['logged'][$id][] = ['TimeStamp' => S()['now'], 'Value' => $v];
            }
        }
        return true;
    }

    // ---------------- Instanzen
    function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID' => S()['obj'][$id]['guid']]]; }
    function IPS_GetInstanceListByModuleID($guid) { $l = []; foreach (S()['obj'] as $k => $o) { if (($o['guid'] ?? '') === $guid) { $l[] = $k; } } return $l; }
    function IPS_GetProperty($id, $n) { return S()['props'][$id][$n]; }
    function IPS_SetProperty($id, $n, $v) { S()['props'][$id][$n] = $v; }
    function IPS_GetConfiguration($id) { return json_encode(S()['props'][$id] ?? []); }
    function IPS_ApplyChanges($id) { if (isset(S()['mods'][$id])) { S()['mods'][$id]->ApplyChanges(); } return true; }
    function createInstance($guid, $class, $name, $parent = 0, $props = [])
    {
        $id = obj(1, $name, $parent);
        S()['obj'][$id]['guid'] = $guid;
        S()['props'][$id] = $props;
        if ($class) {
            $m = new $class($id);
            S()['mods'][$id] = $m;
            $m->Create();
            foreach ($props as $k => $v) {
                S()['props'][$id][$k] = $v;
            }
            $m->ApplyChanges();
        }
        return $id;
    }

    // ---------------- Archiv (Doku: Zähler summiert nur positive Zuwächse ab dem ersten Wert)
    function AC_SetLoggingStatus($ac, $var, $on) { S()['logging'][$var] = $on; return true; }
    function AC_GetLoggingStatus($ac, $var) { return !empty(S()['logging'][$var]); }
    function AC_SetAggregationType($ac, $var, $t) { S()['aggtype'][$var] = $t; return true; }
    function AC_ReAggregateVariable($ac, $var) { return true; }
    function AC_AddLoggedValues($ac, $var, $rows)
    {
        foreach ($rows as $r) {
            S()['logged'][$var][] = ['TimeStamp' => (int) $r['TimeStamp'], 'Value' => $r['Value']];
        }
        usort(S()['logged'][$var], function ($a, $b) { return $a['TimeStamp'] <=> $b['TimeStamp']; });
        return true;
    }
    /** wie Symcon: neueste zuerst */
    function AC_GetLoggedValues($ac, $var, $start, $end, $limit)
    {
        $r = array_values(array_filter(S()['logged'][$var] ?? [], function ($x) use ($start, $end) { return $x['TimeStamp'] >= $start && $x['TimeStamp'] <= $end; }));
        $r = array_reverse($r);
        return $limit > 0 ? array_slice($r, 0, $limit) : $r;
    }
    function AC_GetAggregatedValues($ac, $var, $span, $start, $end, $limit)
    {
        if ($span !== 1) {
            throw new Exception('nur Tagesaggregation nachgebaut');
        }
        $all = S()['logged'][$var] ?? [];
        $counter = (S()['aggtype'][$var] ?? 0) === 1;
        $days = [];
        $prev = null;
        foreach ($all as $x) {
            $d = mktime(0, 0, 0, (int) date('n', $x['TimeStamp']), (int) date('j', $x['TimeStamp']), (int) date('Y', $x['TimeStamp']));
            $v = (float) $x['Value'];
            if ($counter) {
                $delta = $prev === null ? 0.0 : max(0.0, $v - $prev);
                $prev = $v;
                $days[$d]['Avg'] = ($days[$d]['Avg'] ?? 0.0) + $delta;
            } else {
                $days[$d]['Max'] = max($days[$d]['Max'] ?? -INF, $v);
                $days[$d]['Min'] = min($days[$d]['Min'] ?? INF, $v);
                $days[$d]['Avg'] = $v;
            }
        }
        $out = [];
        foreach ($days as $d => $r) {
            if ($d >= mktime(0, 0, 0, (int) date('n', $start), (int) date('j', $start), (int) date('Y', $start)) && $d <= $end) {
                $out[] = $r + ['TimeStamp' => $d, 'Max' => $r['Avg'], 'Min' => 0.0, 'Duration' => 86400];
            }
        }
        return array_reverse($out);
    }
}
