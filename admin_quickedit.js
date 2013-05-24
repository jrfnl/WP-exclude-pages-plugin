/**
 * @package Exclude Pages from Navigation
 * @author Juliette Reinders Folmer
 * @since 2.0
 */
function quickEditInMenu() {
    var $ = jQuery;
    var _edit = inlineEditPost.edit;
    inlineEditPost.edit = function(id) {
        var args = [].slice.call(arguments);
        _edit.apply(this, args);

        if (typeof(id) == 'object') {
            id = this.getId(id);
        }
        if (this.type == 'page') {
            var
            // editRow is the quick-edit row, containing the inputs that need to be updated
            editRow = $('#edit-' + id),
            // postRow is the row shown when a page isn't being edited, which also holds the existing values.
            postRow = $('#post-'+id),

            // get the existing values
            inmenu = !! $('.column-inmenu>input', postRow).attr('checked');

            // set the values in the quick-editor
            $(':input[name="ep_this_page_included"]', editRow).attr('checked', inmenu);
        }
    };
}
// Another way of ensuring inlineEditPost.edit isn't patched until it's defined
if (inlineEditPost) {
    quickEditInMenu();
} else {
    jQuery(quickEditInMenu);
}