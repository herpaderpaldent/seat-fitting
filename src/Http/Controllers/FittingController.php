<?php

namespace Denngarr\Seat\Fitting\Http\Controllers;

use Denngarr\Seat\Fitting\Models\Fitting;
use Seat\Services\Repositories\Character\Info;
use Seat\Services\Repositories\Character\Skills;
use Seat\Services\Repositories\Configuration\UserRespository;
use Seat\Web\Http\Controllers\Controller;
use Denngarr\Seat\Fitting\Validation\FittingValidation;
use Denngarr\Seat\Fitting\Models\Sde\InvType;
use Denngarr\Seat\Fitting\Models\Sde\DgmTypeAttributes;

class FittingController extends Controller
{
    use UserRespository, Skills, Info;

    // don't touch this, or you will lose your hands
    // see dgmAttributeTypes to know what they are
    const REQ_SKILLS_ATTRIBUTES = [
        182, 183, 184, 1285, 1289, 1290,
    ];

    const REQ_SKILLS_LEVELS     = [
        277, 278, 279, 1286, 1287, 1288,
    ];

    const REQ_SKILLS_ATTR_LEVELS = [
        182 => 277,
        183 => 278,
        184 => 279,
        1285 => 1286,
        1289 => 1287,
        1290 => 1288,
    ];

    const DG_PGOUTPUT  = 11;
    const DG_PGLOAD    = 15;
    const DG_CPUOUTPUT = 48;
    const DG_CPULOAD   = 49;

    const RAISE_ALREADY_FULLFILLED = 0;
    const RAISE_SKILL_RAISED       = 1;
    const RAISE_CANNOT_RAISE       = 2;

    const CPU_SKILL_ORDER = [
        // CPU Management
        // Weapon Upgrades
        [3426 => 1],
        [3318 => 1],
        [3426 => 2],
        [3318 => 2],
        [3426 => 3],
        [3318 => 3],
        [3426 => 4],
        [3426 => 5],
        [3318 => 4],
        [3318 => 5],
    ];

    const PG_SKILL_ORDER = [
        // Power Grid Management
        // Shield Upgrades
        // Advanced Weapon Upgrades
        [3413  => 1],
        [3413  => 2],
        [3413  => 3],
        [3413  => 4],
        [3413  => 5],
        [3425  => 1],
        [11207 => 1],
        [3425  => 2],
        [11207 => 2],
        [3425  => 3],
        [11207 => 3],
        [3425  => 4],
        [11207 => 4],
        [3425  => 5],
        [11207 => 5],
    ];

    private $ctx;

    private $cpu_raise_index = 0;

    private $pg_raise_index = 0;

    private $requiredSkills = [];

    public function getFittingList()
    {
       $fitnames = [];
   
        $fittings = Fitting::all();

        if (count($fittings) <= 0)
            return $fitnames;

        foreach ($fittings as $fit) {
            $ship = InvType::where('typeName', $fit->shiptype)->first();

            array_push($fitnames, [
                'id' => $fit->id,
                'shiptype' => $fit->shiptype,
                'fitname' => $fit->fitname,
                'typeID' => $ship->typeID
            ]);
        }

        return $fitnames;
    }

    public function deleteFittingById($id)
    {
        Fitting::destroy($id);

        return "Success";
    }

    public function getSkillsByFitId($id)
    {
        $skillsToons = [];
        $fitting = Fitting::find($id);
        $skillsToons['skills'] = json_decode($this->calculate($fitting->eftfitting));

        $characters = [];
        $characterIds = auth()->user()->associatedCharacterIds();

        foreach ($characterIds as $characterId) {
            $character = $this->getCharacterInformation($characterId);
            array_push($characters, $character);
        }
        foreach ($characters as $character) {
            $index = $character->character_id;
            $skillsToons['characters'][$index]['id']   = $character->character_id;
            $skillsToons['characters'][$index]['name'] = $character->name;

            $characterSkills = $this->getCharacterSkillsInformation($character->character_id);

            foreach ($characterSkills as $skill) {

                $rank = DgmTypeAttributes::where('typeID', $skill->typeID)->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill->typeID]['level'] = $skill->trained_skill_level;
                $skillsToons['characters'][$index]['skill'][$skill->typeID]['rank']  = $rank->valueFloat;

                // Fill in missing skills so Javascript doesn't barf and you have the correct rank
                foreach ($skillsToons['skills'] as $skill) {

                    if (isset($skillsToons['characters'][$index]['skill'][$skill->typeId]))
                        continue;

                    $rank = DgmTypeAttributes::where('typeID', $skill->typeId)->where('attributeID', '275')->first();
                    $skillsToons['characters'][$index]['skill'][$skill->typeId]['level'] = 0;
                    $skillsToons['characters'][$index]['skill'][$skill->typeId]['rank'] = $rank->valueFloat;
                }
            }
        }

        return json_encode($skillsToons);
    }

    public function getEftFittingById($id)
    {
        $fitting = Fitting::find($id);

        return $fitting->eftfitting;
    }

    public function getFittingById($id)
    {
        $fitting = Fitting::find($id);

        return $this->fittingParser($fitting->eftfitting);
    }

    public function getFittingView()
    {
        $fitlist = $this->getFittingList();

        return view('fitting::fitting', compact('fitlist'));
    }

    public function saveFitting(FittingValidation $request)
    {
        if ($request->fitSelection <= 1)
            $fitting = new Fitting();

        if ($request->fitSelection > 1)
            $fitting = Fitting::find($request->fitSelection);

        $eft = explode("\n", $request->eftfitting);
        list($fitting->shiptype, $fitting->fitname) = explode(", ", substr($eft[0], 1, -2));
        $fitting->eftfitting = $request->eftfitting;
        $fitting->save();

        $fitlist = $this->getFittingList();

        return view('fitting::fitting', compact('fitlist'));
    }

    public function postFitting(FittingValidation $request)
    {
        $eft = $request->input('eftfitting');

        return $this->fittingParser($eft);
    }


    public function fittingParser($eft)
    {
        $jsfit = [];
        $data = preg_split("/\r?\n\r?\n/", $eft);
        $jsfit['eft'] = $eft;

        $lowslot = array_filter(preg_split("/\r?\n/", $data[0]));
        list($jsfit['shipname'], $jsfit['fitname']) = explode(",", substr(array_shift($lowslot), 1, -1));

        $midslot = array_filter(preg_split("/\r?\n/", $data[1]));
        $highslot = array_filter(preg_split("/\r?\n/", $data[2]));
        $rigs = array_filter(preg_split("/\r?\n/", $data[3]));
        
        if (($jsfit['shipname'] === 'Tengu') || ($jsfit['shipname'] === 'Loki') ||
            ($jsfit['shipname'] === 'Legion') || ($jsfit['shipname'] === 'Proteus')) {

            $subslot = array_filter(preg_split("/\r?\n/", $data[4]));

            if (count($data) > 5)
                $drones = array_filter(preg_split("/\r?\n/", $data[5]));

        }
        elseif (count($data) > 4)
            $drones = array_filter(preg_split("/\r?\n/", $data[4]));

        // get shipname of first line by removing brackets
        
        $index=0;
        foreach ($lowslot as $slot) {
            $module = explode(",", $slot);
            $item = InvType::where('typeName', $module[0])->first();

            $jsfit['LoSlot' . $index] = [
                'id'  => $item->typeID,
                'name' => $module[0],
            ];

            $index++;
        }
        
        $index=0;
        foreach ($midslot as $slot) {
            $module = explode(",", $slot);
            $item = InvType::where('typeName', $module[0])->first();

            $jsfit['MedSlot' . $index] = [
                'id'   => $item->typeID,
                'name' => $module[0],
            ];

            $index++;
        }

        $index=0;
        foreach ($highslot as $slot) {
            $module = explode(",", $slot);
            $item = InvType::where('typeName', $module[0])->first();

            $jsfit['HiSlot' . $index] = [
                'id'   => $item->typeID,
                'name' => $module[0],
            ];

            $index++;
        }

        $index=0;
        if (isset($subslot))
            foreach ($subslot as $slot) {
                $module = explode(",", $slot);
                $item = InvType::where('typeName', $module[0])->first();

                $jsfit['SubSlot' . $index] = [
                    'id'   => $item->typeID,
                    'name' => $module[0],
                ];

                $index++;
            }
        
        $index=0;
        foreach ($rigs as $slot) {
            $item = InvType::where('typeName', $slot)->first();

            if (empty($item))
                continue;

            $jsfit['RigSlot'.$index] = [
                'id'   => $item->typeID,
                'name' => $slot,
            ];

            $index++;
        }
        
        if (! isset($drones))
            return json_encode($jsfit);

        foreach ($drones as $slot) {
            list($drone, $qty) = explode(" x", $slot);
            $item = InvType::where('typeName', $drone)->first();

            $jsfit['dronebay'][$item->typeID] = [
                'name' => $drone,
                'qty'  => $qty,
            ];
        }

        return(json_encode($jsfit));
    }

    public function postSkills(FittingValidation $request)
    {
        $skillsToons = [];
        $fitting = $request->input('eftfitting');
        $skillsToons['skills'] = json_decode($this->calculate($fitting));

        $characters = $this->getUserCharacters(auth()->user()->id);

        foreach ($characters as $character) {
            $index = $character->characterID;

            $skillsToons['characters'][$index] = [
                'id'   => $character->characterID,
                'name' => $character->characterName,
            ];

            $characterSkills = $this->getCharacterSkillsInformation($character->characterID);

            foreach ($characterSkills as $skill) {
                $rank = DgmTypeAttributes::where('typeID', $skill->typeID)->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill->typeID] = [
                    'level' => $skill->level,
                    'rank'  => $rank->valueFloat,
                ];

                // Fill in missing skills so Javascript doesn't barf and you have the correct rank
                foreach ($skillsToons['skills'] as $skill) {

                    if (isset($skillsToons['characters'][$index]['skill'][$skill->typeId]))
                        continue;

                    $rank = DgmTypeAttributes::where('typeID', $skill->typeId)->where('attributeID', '275')->first();

                    $skillsToons['characters'][$index]['skill'][$skill->typeId] = [
                        'level' => 0,
                        'rank'  => $rank->valueFloat,
                    ];
                }  
            }
        }

        return json_encode($skillsToons);
    }

    public function calculate($fitting)
    {
        $items = $this->parseEftFitting($fitting);
        $item_ids = $this->getUniqueTypeIDs($items['all_item_types']);
        $this->getReqSkillsByTypeIDs($item_ids);
        $this->modifyRequiredSkills($items['fit_items']);

        return json_encode($this->getSkillNames($this->requiredSkills));
    }

    private function modifyRequiredSkills($fitting)
    {
        // skip this, if dogma extension isn't loaded
        if (!extension_loaded('dogma'))
            return;

        dogma_init_context($this->ctx);
        dogma_set_default_skill_level($this->ctx, 0);

        $fitting = $this->convertToTypeIDs($fitting);

        // add ship
        dogma_set_ship($this->ctx, array_shift($fitting));

        // add skills
        foreach ($this->requiredSkills as $skill => $level)
            dogma_set_skill_level($this->ctx, $skill, $level);

        // add modules
        foreach ($fitting as $item)
            dogma_add_module_s($this->ctx, $item, $key, DOGMA_STATE_Active);

        // raise CPU skills
        $raise = null;
        while ($this->getAttribValue(self::DG_CPUOUTPUT) < $this->getAttribValue(self::DG_CPULOAD) && $raise !== self::RAISE_CANNOT_RAISE) {
            $raise = $this->raiseSkill('cpu');
        }

        // raise Powergrid skills
        $raise = null;
        while ($this->getAttribValue(self::DG_PGOUTPUT) < $this->getAttribValue(self::DG_PGLOAD) && $raise !== self::RAISE_CANNOT_RAISE) {
            $raise = $this->raiseSkill('powergrid');
        }
    }

    private function getAttribValue($attrib)
    {
        dogma_get_ship_attribute($this->ctx, $attrib, $ret);

        return $ret;
    }

    private function raiseSkill($type)
    {
        switch ($type) {
            case 'cpu':
                $index =& $this->cpu_raise_index;
                $skillsOrder = self::CPU_SKILL_ORDER;
                break;
            case 'powergrid':
                $index =& $this->pg_raise_index;
                $skillsOrder = self::PG_SKILL_ORDER;
                break;
        }

        if (!isset($skillsOrder[$index]))
            return self::RAISE_CANNOT_RAISE;

        $skill = $skillsOrder[$index];
        $skillId = key($skill);
        $level = $skill[$skillId];

        $index++;

        if (!isset($this->requiredSkills[$skillId]) || $this->requiredSkills[$skillId] < $level) {
            dogma_set_skill_level($this->ctx, $skillId, $level);
            $this->requiredSkills[$skillId] = $level;

            return self::RAISE_SKILL_RAISED;
        }

        return self::RAISE_ALREADY_FULLFILLED;
    }

    private function getReqSkillsByTypeIDs($typeIDs)
    {
        $attributeids = array_merge(array_keys(self::REQ_SKILLS_ATTR_LEVELS), array_values(self::REQ_SKILLS_ATTR_LEVELS));

        foreach ($typeIDs as $type) {
            $res = DgmTypeAttributes::where('typeid', $type['typeID'])->wherein('attributeID', $attributeids)->get();

            if (count($res) == 0)
                continue;

            $skillsToAdd = $this->prepareRequiredSkills($res);
            $this->buildMinRequiredSkills($skillsToAdd);
            $this->findSubRequiredSkills($skillsToAdd);
        }
    }

    private function findSubRequiredSkills($skills)
    {
        $toFind = [];

        foreach ($skills as $skill => $level)
            $toFind[] = ['typeID' => $skill];

        $this->getReqSkillsByTypeIDs($toFind);
    }

    private function buildMinRequiredSkills($toAdd)
    {
        foreach ($toAdd as $skill => $level)
            if (! isset($this->requiredSkills[$skill]) || $this->requiredSkills[$skill] < $level)
                $this->requiredSkills[$skill] = $level;
    }

    private function prepareRequiredSkills($attributes)
    {
        $skills = [];
        $keys = [];

        // build an array of attributes
        foreach ($attributes as $attribute) {
            $attribValue = $attribute['valueInt'] !== null ? $attribute['valueInt'] : $attribute['valueFloat'];
            $keys[$attribute['attributeID']] = $attribValue;
        }

        // iterate over all attributes and build the skill array
        foreach (self::REQ_SKILLS_ATTR_LEVELS as $k => $v) {
            if (isset($keys[$k]))
                $skills[$keys[$k]] = $keys[$v];
        }

        return $skills;
    }

    private function parseEftFitting($fitting)
    {
        $fitting = $this->sanatizeFittingBlock($fitting);
        $fitsplit = explode("\n", $fitting);

        // get shipname of first line by removing brackets
        list($shipname, $fitname) = explode(", ", substr(array_shift($fitsplit), 1, -1));

        $fit_all_items = [];
        $fit_calc_items = [];

        // first element is always the ship type
        $fit_all_items[] = $fit_calc_items[] = $shipname;

        foreach ($fitsplit as $key => $line) {
            // split line to get charge
            $linesplit = explode(",", $line);

            if (isset($linesplit[1]))
                $fit_all_items[] = $linesplit[1];

            // don't add drones to fitting
            if (preg_match("/ x\d+/", $linesplit[0])) {
                $fit_all_items[] = $this->sanatizeTypeName($linesplit[0]);
            } else {
                $fit_all_items[] = $fit_calc_items[] = $this->sanatizeTypeName($linesplit[0]);
            }
        }

        return [
            'all_item_types' => array_unique($fit_all_items),
            'fit_items' => $fit_calc_items
        ];
    }

    private function getUniqueTypeIDs($items)
    {
        return InvType::wherein('typeName', $items)->get();
    }

    private function convertToTypeIDs($items)
    {
        foreach ($items as $key => $item) {
            $type = InvType::where('typeName', $item)->first();
            $items[$key] = InvType::where('typeName', $item)->first()->id;
        }

        return $items;
    }

    private function sanatizeFittingBlock($fitting)
    {
        // remove useless empty lines and whatnot
        return ltrim(rtrim(preg_replace("/^[ \t]*[\r\n]+/m", "", $fitting)));
    }

    private function sanatizeTypeName($item)
    {
        // remove amount for charges
        // sample: Scourge Rage Heavy Assault Missile x66
        return ltrim(rtrim(preg_replace("/ x\d+/", "", $item)));
    }

    private function getSkillNames($types)
    {
        $skills = [];

        foreach ($types as $skill_id => $level) {
            $res = InvType::where('typeID', $skill_id)->first();

            $skills[] = [
                'typeId' => $skill_id,
                'typeName' => $res->typeName,
                'level' => $level
            ];
        }

        ksort($skills);

        return $skills;
    }

    private function getItemNames($items)
    {
        $itemNames = [];

        foreach ($items as $typeid) {
            $res = InvType::where('typeID', $typeid)->first();
            $itemNames[] = $res->typeName;
        }

        ksort($itemNames);

        return $itemNames;
    }
}
