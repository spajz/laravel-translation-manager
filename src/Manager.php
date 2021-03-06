<?php namespace Barryvdh\TranslationManager;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Events\Dispatcher;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class Manager{

    /** @var \Illuminate\Foundation\Application  */
    protected $app;
    /** @var \Illuminate\Filesystem\Filesystem  */
    protected $files;
    /** @var \Illuminate\Events\Dispatcher  */
    protected $events;

    protected $config;

    public function __construct(Application $app, Filesystem $files, Dispatcher $events)
    {
        $this->app = $app;
        $this->files = $files;
        $this->events = $events;
        $this->config = $app['config']['translation-manager'];
    }

    public function missingKey($namespace, $group, $key)
    {
        if(!in_array($group, $this->config['exclude_groups'])) {
            Translation::firstOrCreate(array(
                'locale' => $this->app['config']['app.locale'],
                'group' => $group,
                'key' => $key,
            ));
        }
    }

    public function importTranslations($replace = false)
    {
        $dirs = $this->getDirectories();
        $counter = 0;
        foreach($dirs as $langPath){
            $namespace = null;
            if(strpos($langPath, 'Modules')){
                $namespace = strtolower(basename(dirname(dirname($langPath))));
            }
            $locale = basename($langPath);

            foreach($this->files->files($langPath) as $file){

                $info = pathinfo($file);
                $group = $info['filename'];

                if(in_array($group, $this->config['exclude_groups'])) {
                    continue;
                }

                $translations = \Lang::getLoader()->load($locale, $group, $namespace);

                if ($translations && is_array($translations)) {
                    foreach(array_dot($translations) as $key => $value){
                        $value = (string) $value;
                        $translation = Translation::firstOrNew(array(
                            'locale' => $locale,
                            'group' => $namespace ? $namespace . '::' . $group : $group,
                            'key' => $key,
                        ));

                        // Check if the database is different then the files
                        $newStatus = $translation->value === $value ? Translation::STATUS_SAVED : Translation::STATUS_CHANGED;
                        if($newStatus !== (int) $translation->status){
                            $translation->status = $newStatus;
                        }

                        // Only replace when empty, or explicitly told so
                        if($replace || !$translation->value){
                            $translation->value = $value;
                        }

                        $translation->save();

                        $counter++;
                    }
                }
            }
        }
        return $counter;
    }

    protected function getDirectories()
    {
        $modulesDirs = $this->files->directories(app_path('Modules/*/lang'));
        $resourcesDirs = $this->files->directories($this->app->langPath());
        return array_merge($modulesDirs, $resourcesDirs);
    }

    public function findTranslations($path = null)
    {
        $path = $path ?: [base_path('app'), base_path('resources'), base_path('config')];
        $keys = array();
        $functions =  array('trans', 'trans_choice', 'Lang::get', 'Lang::choice', 'Lang::trans', 'Lang::transChoice', '@lang', '@choice', 'trans2');
        $pattern =                              // See http://regexr.com/392hu
            "(".implode('|', $functions) .")".  // Must start with one of the functions
            "\(".                               // Match opening parenthese
            "[\'\"]".                           // Match " or '
            "(".                                // Start a new group to match:
            "[a-zA-Z0-9_-]+".               // Must start with group
            "(([\.\:])[^\1)]+)+".                // Be followed by one or more items/keys
            ")".                                // Close group
            "[\'\"]".                           // Closing quote
            "[\),]";                            // Close parentheses or new parameter

        // Find all PHP + Twig files in the app folder, except for storage
        $finder = new Finder();
        $finder->in($path)->exclude('storage')->name('*.php')->name('*.twig')->files();

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            // Search the current file for the pattern
            if(preg_match_all("/$pattern/siU", $file->getContents(), $matches)) {
                // Get all matches
                foreach ($matches[2] as $key) {
                    $keys[] = $key;
                }
            }
        }

        // Remove duplicates
        $keys = array_unique($keys);

        // Add the translations to the database, if not existing.
        foreach($keys as $key){
            // Split the group and item
            list($group, $item) = explode('.', $key, 2);
            $this->missingKey('', $group, $item);
        }

        // Return the number of found translations
        return count($keys);
    }

    public function exportTranslations($group)
    {
        if(!in_array($group, $this->config['exclude_groups'])) {
            if($group == '*')
                return $this->exportAllTranslations();

            $tree = $this->makeTree(Translation::where('group', $group)->whereNotNull('value')->get());

            foreach($tree as $locale => $groups){
                if(isset($groups[$group])){
                    $translations = $groups[$group];
                    $path = $this->getPath($group, $locale);
                    $output = "<?php\n\nreturn ".var_export($translations, true).";\n";
                    $this->files->put($path, $output);
                }
            }
            Translation::where('group', $group)->whereNotNull('value')->update(array('status' => Translation::STATUS_SAVED));
        }
    }

    protected function getPath($group, $locale)
    {
        // Is module?
        if(strpos($group, '::')){
            $parts = explode('::', $group);
            $path = $this->app->path(). '/Modules/' . ucfirst($parts[0]) . '/lang/'.$locale.'/'.$parts[1].'.php';
        } else {
            $path = $this->app->langPath().'/'.$locale.'/'.$group.'.php';
        }
        return $path;
    }

    public function exportAllTranslations()
    {
        $groups = Translation::whereNotNull('value')->select(DB::raw('DISTINCT `group`'))->get('group');

        foreach($groups as $group){
            $this->exportTranslations($group->group);
        }
    }

    public function cleanTranslations()
    {
        Translation::whereNull('value')->delete();
    }

    public function truncateTranslations()
    {
        Translation::truncate();
    }

    protected function makeTree($translations)
    {
        $array = array();
        foreach($translations as $translation){
            array_set($array[$translation->locale][$translation->group], $translation->key, $translation->value);
        }
        return $array;
    }

    public function getConfig($key = null)
    {
        if($key == null) {
            return $this->config;
        }
        else {
            return $this->config[$key];
        }
    }

}
