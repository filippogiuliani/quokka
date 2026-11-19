// Quokka: the categories the sorter puts on the emails, as IMAP keywords (see sorter/classify.py)
import ko from 'ko';
import { SettingsGet } from 'Common/Globals';
import { i18n, translateTrigger } from 'Common/Translator';

// Their names are in the translations, MESSAGE_TAGS/<keyword>
export const CATEGORY_KEYS = ['bills', 'school', 'bank', 'shipping', 'account', 'social', 'promotions'];

/** [[keyword, name], …] in the interface's language */
export const allCategories = () => CATEGORY_KEYS.map(key => [key, i18n('MESSAGE_TAGS/' + key)]);

/** The ones each person turned off (Settings → Categories); null until read from the app data */
export const hiddenCategories = ko.observable(null);

/** The categories this person sees: in the column, the filters, the rows and the Categoria menu */
export const CATEGORIES = ko.computed(() => {
	translateTrigger();
	const hidden = hiddenCategories() ?? (SettingsGet('QuokkaHiddenCategories') || []);
	return allCategories().filter(([key]) => !hidden.includes(key));
}, null, { deferEvaluation: true });

/** The category of an email from its flags (lower case), or undefined */
export const categoryOf = flags => CATEGORIES().find(([key]) => flags.includes(key));

/** The search that lists one category, as the advanced search writes it */
export const categorySearch = key => 'keyword=' + key;

/** A category or a quick filter (Non lette, Con allegati): shown by the heading and the pills, not as a search */
export const isQuickSearch = search => ['unseen=1', 'attachment=1'].includes(search)
	|| CATEGORY_KEYS.some(key => search === categorySearch(key));

// for the settings tab (plugins/quokka-extras)
rl.quokkaCategories = { all: allCategories, hidden: hiddenCategories, visible: CATEGORIES };
