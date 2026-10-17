<?php
/*
 * [crypto-wallet-gen] Trust-Wallet compatible wallet generator (pure PHP + bcmath).
 * BIP39 (12 words, English) -> BIP32/BIP44:
 *   TRON  m/44'/195'/0'/0/0  -> USDT TRC20 address
 *   BSC   m/44'/60'/0'/0/0   -> USDT BEP20 address
 * The mnemonic is only returned to the caller; nothing here writes it anywhere.
 */

const CWG_P = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
const CWG_N = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
const CWG_GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
const CWG_GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';
const CWG_WORDS = 'abandon ability able about above absent absorb abstract absurd abuse access accident account accuse achieve acid acoustic acquire across act action actor actress actual adapt add addict address adjust admit adult advance advice aerobic affair afford afraid again age agent agree ahead aim air airport aisle alarm album alcohol alert alien all alley allow almost alone alpha already also alter always amateur amazing among amount amused analyst anchor ancient anger angle angry animal ankle announce annual another answer antenna antique anxiety any apart apology appear apple approve april arch arctic area arena argue arm armed armor army around arrange arrest arrive arrow art artefact artist artwork ask aspect assault asset assist assume asthma athlete atom attack attend attitude attract auction audit august aunt author auto autumn average avocado avoid awake aware away awesome awful awkward axis baby bachelor bacon badge bag balance balcony ball bamboo banana banner bar barely bargain barrel base basic basket battle beach bean beauty because become beef before begin behave behind believe below belt bench benefit best betray better between beyond bicycle bid bike bind biology bird birth bitter black blade blame blanket blast bleak bless blind blood blossom blouse blue blur blush board boat body boil bomb bone bonus book boost border boring borrow boss bottom bounce box boy bracket brain brand brass brave bread breeze brick bridge brief bright bring brisk broccoli broken bronze broom brother brown brush bubble buddy budget buffalo build bulb bulk bullet bundle bunker burden burger burst bus business busy butter buyer buzz cabbage cabin cable cactus cage cake call calm camera camp can canal cancel candy cannon canoe canvas canyon capable capital captain car carbon card cargo carpet carry cart case cash casino castle casual cat catalog catch category cattle caught cause caution cave ceiling celery cement census century cereal certain chair chalk champion change chaos chapter charge chase chat cheap check cheese chef cherry chest chicken chief child chimney choice choose chronic chuckle chunk churn cigar cinnamon circle citizen city civil claim clap clarify claw clay clean clerk clever click client cliff climb clinic clip clock clog close cloth cloud clown club clump cluster clutch coach coast coconut code coffee coil coin collect color column combine come comfort comic common company concert conduct confirm congress connect consider control convince cook cool copper copy coral core corn correct cost cotton couch country couple course cousin cover coyote crack cradle craft cram crane crash crater crawl crazy cream credit creek crew cricket crime crisp critic crop cross crouch crowd crucial cruel cruise crumble crunch crush cry crystal cube culture cup cupboard curious current curtain curve cushion custom cute cycle dad damage damp dance danger daring dash daughter dawn day deal debate debris decade december decide decline decorate decrease deer defense define defy degree delay deliver demand demise denial dentist deny depart depend deposit depth deputy derive describe desert design desk despair destroy detail detect develop device devote diagram dial diamond diary dice diesel diet differ digital dignity dilemma dinner dinosaur direct dirt disagree discover disease dish dismiss disorder display distance divert divide divorce dizzy doctor document dog doll dolphin domain donate donkey donor door dose double dove draft dragon drama drastic draw dream dress drift drill drink drip drive drop drum dry duck dumb dune during dust dutch duty dwarf dynamic eager eagle early earn earth easily east easy echo ecology economy edge edit educate effort egg eight either elbow elder electric elegant element elephant elevator elite else embark embody embrace emerge emotion employ empower empty enable enact end endless endorse enemy energy enforce engage engine enhance enjoy enlist enough enrich enroll ensure enter entire entry envelope episode equal equip era erase erode erosion error erupt escape essay essence estate eternal ethics evidence evil evoke evolve exact example excess exchange excite exclude excuse execute exercise exhaust exhibit exile exist exit exotic expand expect expire explain expose express extend extra eye eyebrow fabric face faculty fade faint faith fall false fame family famous fan fancy fantasy farm fashion fat fatal father fatigue fault favorite feature february federal fee feed feel female fence festival fetch fever few fiber fiction field figure file film filter final find fine finger finish fire firm first fiscal fish fit fitness fix flag flame flash flat flavor flee flight flip float flock floor flower fluid flush fly foam focus fog foil fold follow food foot force forest forget fork fortune forum forward fossil foster found fox fragile frame frequent fresh friend fringe frog front frost frown frozen fruit fuel fun funny furnace fury future gadget gain galaxy gallery game gap garage garbage garden garlic garment gas gasp gate gather gauge gaze general genius genre gentle genuine gesture ghost giant gift giggle ginger giraffe girl give glad glance glare glass glide glimpse globe gloom glory glove glow glue goat goddess gold good goose gorilla gospel gossip govern gown grab grace grain grant grape grass gravity great green grid grief grit grocery group grow grunt guard guess guide guilt guitar gun gym habit hair half hammer hamster hand happy harbor hard harsh harvest hat have hawk hazard head health heart heavy hedgehog height hello helmet help hen hero hidden high hill hint hip hire history hobby hockey hold hole holiday hollow home honey hood hope horn horror horse hospital host hotel hour hover hub huge human humble humor hundred hungry hunt hurdle hurry hurt husband hybrid ice icon idea identify idle ignore ill illegal illness image imitate immense immune impact impose improve impulse inch include income increase index indicate indoor industry infant inflict inform inhale inherit initial inject injury inmate inner innocent input inquiry insane insect inside inspire install intact interest into invest invite involve iron island isolate issue item ivory jacket jaguar jar jazz jealous jeans jelly jewel job join joke journey joy judge juice jump jungle junior junk just kangaroo keen keep ketchup key kick kid kidney kind kingdom kiss kit kitchen kite kitten kiwi knee knife knock know lab label labor ladder lady lake lamp language laptop large later latin laugh laundry lava law lawn lawsuit layer lazy leader leaf learn leave lecture left leg legal legend leisure lemon lend length lens leopard lesson letter level liar liberty library license life lift light like limb limit link lion liquid list little live lizard load loan lobster local lock logic lonely long loop lottery loud lounge love loyal lucky luggage lumber lunar lunch luxury lyrics machine mad magic magnet maid mail main major make mammal man manage mandate mango mansion manual maple marble march margin marine market marriage mask mass master match material math matrix matter maximum maze meadow mean measure meat mechanic medal media melody melt member memory mention menu mercy merge merit merry mesh message metal method middle midnight milk million mimic mind minimum minor minute miracle mirror misery miss mistake mix mixed mixture mobile model modify mom moment monitor monkey monster month moon moral more morning mosquito mother motion motor mountain mouse move movie much muffin mule multiply muscle museum mushroom music must mutual myself mystery myth naive name napkin narrow nasty nation nature near neck need negative neglect neither nephew nerve nest net network neutral never news next nice night noble noise nominee noodle normal north nose notable note nothing notice novel now nuclear number nurse nut oak obey object oblige obscure observe obtain obvious occur ocean october odor off offer office often oil okay old olive olympic omit once one onion online only open opera opinion oppose option orange orbit orchard order ordinary organ orient original orphan ostrich other outdoor outer output outside oval oven over own owner oxygen oyster ozone pact paddle page pair palace palm panda panel panic panther paper parade parent park parrot party pass patch path patient patrol pattern pause pave payment peace peanut pear peasant pelican pen penalty pencil people pepper perfect permit person pet phone photo phrase physical piano picnic picture piece pig pigeon pill pilot pink pioneer pipe pistol pitch pizza place planet plastic plate play please pledge pluck plug plunge poem poet point polar pole police pond pony pool popular portion position possible post potato pottery poverty powder power practice praise predict prefer prepare present pretty prevent price pride primary print priority prison private prize problem process produce profit program project promote proof property prosper protect proud provide public pudding pull pulp pulse pumpkin punch pupil puppy purchase purity purpose purse push put puzzle pyramid quality quantum quarter question quick quit quiz quote rabbit raccoon race rack radar radio rail rain raise rally ramp ranch random range rapid rare rate rather raven raw razor ready real reason rebel rebuild recall receive recipe record recycle reduce reflect reform refuse region regret regular reject relax release relief rely remain remember remind remove render renew rent reopen repair repeat replace report require rescue resemble resist resource response result retire retreat return reunion reveal review reward rhythm rib ribbon rice rich ride ridge rifle right rigid ring riot ripple risk ritual rival river road roast robot robust rocket romance roof rookie room rose rotate rough round route royal rubber rude rug rule run runway rural sad saddle sadness safe sail salad salmon salon salt salute same sample sand satisfy satoshi sauce sausage save say scale scan scare scatter scene scheme school science scissors scorpion scout scrap screen script scrub sea search season seat second secret section security seed seek segment select sell seminar senior sense sentence series service session settle setup seven shadow shaft shallow share shed shell sheriff shield shift shine ship shiver shock shoe shoot shop short shoulder shove shrimp shrug shuffle shy sibling sick side siege sight sign silent silk silly silver similar simple since sing siren sister situate six size skate sketch ski skill skin skirt skull slab slam sleep slender slice slide slight slim slogan slot slow slush small smart smile smoke smooth snack snake snap sniff snow soap soccer social sock soda soft solar soldier solid solution solve someone song soon sorry sort soul sound soup source south space spare spatial spawn speak special speed spell spend sphere spice spider spike spin spirit split spoil sponsor spoon sport spot spray spread spring spy square squeeze squirrel stable stadium staff stage stairs stamp stand start state stay steak steel stem step stereo stick still sting stock stomach stone stool story stove strategy street strike strong struggle student stuff stumble style subject submit subway success such sudden suffer sugar suggest suit summer sun sunny sunset super supply supreme sure surface surge surprise surround survey suspect sustain swallow swamp swap swarm swear sweet swift swim swing switch sword symbol symptom syrup system table tackle tag tail talent talk tank tape target task taste tattoo taxi teach team tell ten tenant tennis tent term test text thank that theme then theory there they thing this thought three thrive throw thumb thunder ticket tide tiger tilt timber time tiny tip tired tissue title toast tobacco today toddler toe together toilet token tomato tomorrow tone tongue tonight tool tooth top topic topple torch tornado tortoise toss total tourist toward tower town toy track trade traffic tragic train transfer trap trash travel tray treat tree trend trial tribe trick trigger trim trip trophy trouble truck true truly trumpet trust truth try tube tuition tumble tuna tunnel turkey turn turtle twelve twenty twice twin twist two type typical ugly umbrella unable unaware uncle uncover under undo unfair unfold unhappy uniform unique unit universe unknown unlock until unusual unveil update upgrade uphold upon upper upset urban urge usage use used useful useless usual utility vacant vacuum vague valid valley valve van vanish vapor various vast vault vehicle velvet vendor venture venue verb verify version very vessel veteran viable vibrant vicious victory video view village vintage violin virtual virus visa visit visual vital vivid vocal voice void volcano volume vote voyage wage wagon wait walk wall walnut want warfare warm warrior wash wasp waste water wave way wealth weapon wear weasel weather web wedding weekend weird welcome west wet whale what wheat wheel when where whip whisper wide width wife wild will win window wine wing wink winner winter wire wisdom wise wish witness wolf woman wonder wood wool word work world worry worth wrap wreck wrestle wrist write wrong yard year yellow you young youth zebra zero zone zoo';

function cwgWords(): array
{
    static $w = null;
    return $w ??= explode(' ', CWG_WORDS);
}

/* ---------- big integer helpers (bcmath) ---------- */
function cwgMod(string $a, string $m): string
{
    $r = bcmod($a, $m, 0);
    return bccomp($r, '0') < 0 ? bcadd($r, $m, 0) : $r;
}

function cwgBinToDec(string $bin): string
{
    $d = '0';
    foreach (str_split(bin2hex($bin), 8) as $chunk) {
        $d = bcadd(bcmul($d, bcpow('16', (string) strlen($chunk), 0), 0), (string) hexdec($chunk), 0);
    }
    return $d;
}

function cwgDecToBin(string $d, int $len = 32): string
{
    $out = '';
    while (bccomp($d, '0') > 0) {
        $out = chr((int) bcmod($d, '256', 0)) . $out;
        $d = bcdiv($d, '256', 0);
    }
    return str_pad($out, $len, "\0", STR_PAD_LEFT);
}

/* ---------- secp256k1 (Jacobian coordinates, a = 0) ---------- */
function cwgDouble(?array $P): ?array
{
    if ($P === null || $P[1] === '0') {
        return null;
    }
    $p = CWG_P;
    [$X, $Y, $Z] = $P;
    $YY = cwgMod(bcmul($Y, $Y, 0), $p);
    $S = cwgMod(bcmul('4', bcmul($X, $YY, 0), 0), $p);
    $M = cwgMod(bcmul('3', bcmul($X, $X, 0), 0), $p);
    $X3 = cwgMod(bcsub(bcmul($M, $M, 0), bcmul('2', $S, 0), 0), $p);
    $Y3 = cwgMod(bcsub(bcmul($M, bcsub($S, $X3, 0), 0), bcmul('8', bcmul($YY, $YY, 0), 0), 0), $p);
    $Z3 = cwgMod(bcmul('2', bcmul($Y, $Z, 0), 0), $p);
    return [$X3, $Y3, $Z3];
}

// P (Jacobian) + Q (affine)
function cwgAddAffine(?array $P, array $Q): ?array
{
    if ($P === null) {
        return [$Q[0], $Q[1], '1'];
    }
    $p = CWG_P;
    [$X1, $Y1, $Z1] = $P;
    $Z1Z1 = cwgMod(bcmul($Z1, $Z1, 0), $p);
    $U2 = cwgMod(bcmul($Q[0], $Z1Z1, 0), $p);
    $S2 = cwgMod(bcmul($Q[1], bcmul($Z1, $Z1Z1, 0), 0), $p);
    $H = cwgMod(bcsub($U2, $X1, 0), $p);
    $R = cwgMod(bcsub($S2, $Y1, 0), $p);
    if ($H === '0') {
        return $R === '0' ? cwgDouble($P) : null;
    }
    $HH = cwgMod(bcmul($H, $H, 0), $p);
    $HHH = cwgMod(bcmul($H, $HH, 0), $p);
    $V = cwgMod(bcmul($X1, $HH, 0), $p);
    $X3 = cwgMod(bcsub(bcsub(bcmul($R, $R, 0), $HHH, 0), bcmul('2', $V, 0), 0), $p);
    $Y3 = cwgMod(bcsub(bcmul($R, bcsub($V, $X3, 0), 0), bcmul($Y1, $HHH, 0), 0), $p);
    $Z3 = cwgMod(bcmul($Z1, $H, 0), $p);
    return [$X3, $Y3, $Z3];
}

// k*G -> affine [x, y]
function cwgPubPoint(string $k): array
{
    $bits = '';
    foreach (str_split(cwgDecToBin($k)) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $G = [CWG_GX, CWG_GY];
    $R = null;
    for ($i = 0, $l = strlen($bits); $i < $l; $i++) {
        $R = cwgDouble($R);
        if ($bits[$i] === '1') {
            $R = cwgAddAffine($R, $G);
        }
    }
    if ($R === null) {
        throw new RuntimeException('point at infinity');
    }
    $zi = bcpowmod($R[2], bcsub(CWG_P, '2', 0), CWG_P, 0);
    $zi2 = cwgMod(bcmul($zi, $zi, 0), CWG_P);
    $x = cwgMod(bcmul($R[0], $zi2, 0), CWG_P);
    $y = cwgMod(bcmul($R[1], bcmul($zi2, $zi, 0), 0), CWG_P);
    return [$x, $y];
}

function cwgCompressed(array $pt): string
{
    return (bcmod($pt[1], '2', 0) === '0' ? "\x02" : "\x03") . cwgDecToBin($pt[0]);
}

/* ---------- Keccak-256 (Ethereum padding 0x01), 64-bit lanes ---------- */
function cwgRotl(int $x, int $n): int
{
    return $n === 0 ? $x : (($x << $n) | (($x >> (64 - $n)) & ~(-1 << $n)));
}

function cwgKeccak256(string $in): string
{
    static $rc = null;
    if ($rc === null) {
        $pairs = [[0x00000000, 0x00000001], [0x00000000, 0x00008082], [0x80000000, 0x0000808A], [0x80000000, 0x80008000],
            [0x00000000, 0x0000808B], [0x00000000, 0x80000001], [0x80000000, 0x80008081], [0x80000000, 0x00008009],
            [0x00000000, 0x0000008A], [0x00000000, 0x00000088], [0x00000000, 0x80008009], [0x00000000, 0x8000000A],
            [0x00000000, 0x8000808B], [0x80000000, 0x0000008B], [0x80000000, 0x00008089], [0x80000000, 0x00008003],
            [0x80000000, 0x00008002], [0x80000000, 0x00000080], [0x00000000, 0x0000800A], [0x80000000, 0x8000000A],
            [0x80000000, 0x80008081], [0x80000000, 0x00008080], [0x00000000, 0x80000001], [0x80000000, 0x80008008]];
        $rc = array_map(fn($p) => ($p[0] << 32) | $p[1], $pairs);
    }
    $rot = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];
    $rate = 136;
    $in .= "\x01";
    $in .= str_repeat("\0", ($rate - strlen($in) % $rate) % $rate);
    $in[strlen($in) - 1] = chr(ord($in[strlen($in) - 1]) | 0x80);
    $A = array_fill(0, 25, 0);
    foreach (str_split($in, $rate) as $block) {
        $lanes = array_values(unpack('P17', $block));
        for ($i = 0; $i < 17; $i++) {
            $A[$i] ^= $lanes[$i];
        }
        for ($round = 0; $round < 24; $round++) {
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = $A[$x] ^ $A[$x + 5] ^ $A[$x + 10] ^ $A[$x + 15] ^ $A[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $D = $C[($x + 4) % 5] ^ cwgRotl($C[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $A[$x + $y] ^= $D;
                }
            }
            $B = [];
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $B[$y + 5 * ((2 * $x + 3 * $y) % 5)] = cwgRotl($A[$x + 5 * $y], $rot[$x + 5 * $y]);
                }
            }
            for ($y = 0; $y < 25; $y += 5) {
                for ($x = 0; $x < 5; $x++) {
                    $A[$x + $y] = $B[$x + $y] ^ ((~$B[($x + 1) % 5 + $y]) & $B[($x + 2) % 5 + $y]);
                }
            }
            $A[0] ^= $rc[$round];
        }
    }
    return pack('P4', $A[0], $A[1], $A[2], $A[3]);
}

/* ---------- BIP39 ---------- */
function cwgEntropyToMnemonic(string $entropy): string
{
    $bits = '';
    foreach (str_split($entropy . hash('sha256', $entropy, true)) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $len = strlen($entropy) * 8;
    $bits = substr($bits, 0, $len + intdiv($len, 32));
    $w = cwgWords();
    return implode(' ', array_map(fn($b) => $w[bindec($b)], str_split($bits, 11)));
}

function cwgMnemonicValid(string $mnemonic): bool
{
    $idx = array_flip(cwgWords());
    $parts = explode(' ', $mnemonic);
    if (!in_array(count($parts), [12, 15, 18, 21, 24], true)) {
        return false;
    }
    $bits = '';
    foreach ($parts as $p) {
        if (!isset($idx[$p])) {
            return false;
        }
        $bits .= str_pad(decbin($idx[$p]), 11, '0', STR_PAD_LEFT);
    }
    $cs = intdiv(strlen($bits), 33);
    $entropy = '';
    foreach (str_split(substr($bits, 0, -$cs), 8) as $b) {
        $entropy .= chr(bindec($b));
    }
    return cwgEntropyToMnemonic($entropy) === $mnemonic;
}

/* ---------- BIP32 ---------- */
function cwgDerive(string $seed, array $path): string
{
    $I = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
    $k = cwgBinToDec(substr($I, 0, 32));
    $c = substr($I, 32);
    foreach ($path as $index) {
        $hard = $index >= 0x80000000;
        $data = $hard ? "\0" . cwgDecToBin($k) : cwgCompressed(cwgPubPoint($k));
        $I = hash_hmac('sha512', $data . pack('N', $index), $c, true);
        $IL = cwgBinToDec(substr($I, 0, 32));
        if (bccomp($IL, CWG_N) >= 0) {
            throw new RuntimeException('invalid child key');
        }
        $k = cwgMod(bcadd($IL, $k, 0), CWG_N);
        if ($k === '0') {
            throw new RuntimeException('invalid child key');
        }
        $c = substr($I, 32);
    }
    return $k;
}

function cwgAddr20(string $k): string
{
    $pt = cwgPubPoint($k);
    return substr(cwgKeccak256(cwgDecToBin($pt[0]) . cwgDecToBin($pt[1])), 12);
}

function cwgEip55(string $addr20): string
{
    $hex = bin2hex($addr20);
    $h = bin2hex(cwgKeccak256($hex));
    $out = '0x';
    for ($i = 0; $i < 40; $i++) {
        $out .= ctype_alpha($hex[$i]) && hexdec($h[$i]) >= 8 ? strtoupper($hex[$i]) : $hex[$i];
    }
    return $out;
}

function cwgBase58Check(string $payload): string
{
    $bin = $payload . substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $n = cwgBinToDec($bin);
    $out = '';
    while (bccomp($n, '0') > 0) {
        $out = $alphabet[(int) bcmod($n, '58', 0)] . $out;
        $n = bcdiv($n, '58', 0);
    }
    for ($i = 0; $i < strlen($bin) && $bin[$i] === "\0"; $i++) {
        $out = '1' . $out;
    }
    return $out;
}

/** @return array{trc20:string,bep20:string} */
function cwgAddresses(string $mnemonic): array
{
    $seed = hash_pbkdf2('sha512', $mnemonic, 'mnemonic', 2048, 64, true);
    $h = 0x80000000;
    $tron = cwgDerive($seed, [44 + $h, 195 + $h, 0 + $h, 0, 0]);
    $eth = cwgDerive($seed, [44 + $h, 60 + $h, 0 + $h, 0, 0]);
    return [
        'trc20' => cwgBase58Check("\x41" . cwgAddr20($tron)),
        'bep20' => cwgEip55(cwgAddr20($eth)),
    ];
}

/** @return array{mnemonic:string,trc20:string,bep20:string} */
function cwgNewWallet(): array
{
    $mnemonic = cwgEntropyToMnemonic(random_bytes(16));
    return ['mnemonic' => $mnemonic] + cwgAddresses($mnemonic);
}
