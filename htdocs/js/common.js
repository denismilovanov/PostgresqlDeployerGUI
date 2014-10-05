Messager = {

    alert: function (status, message) {
        $("#messager").removeClass("alert-success").removeClass("alert-danger");
        if (status == 0) {
            $("#messager").addClass("alert-danger");
        } else {
            $("#messager").addClass("alert-success");
        }
        $("#messager").html(message.replace(/([^>])\n/g, '$1<br/>'));
        $("#messager").slideDown(500);
        if (status == 1) {
            setTimeout(function() {
                $("#messager").slideUp(500);
            }, 3000);
        }
        $("#messager").click(function() {
            $("#messager").slideUp(500);
        })
    }

}

Git = {

    database_name: '',
    schemas_states: {},
    schemas_objects_states: {},
    last_hash: '',
    diff_template: '',
    commits_template: '',

    request: function(method, url, data, success) {
        $.ajax({
            url: url,
            dataType: "json",
            type: method,
            data: data
        }).done(function(data) {
            success(data);
        }).fail(function() {
            Messager.alert(0, 'Unable to make request.');
        });
    },

    checkout: function (hash) {
        Git.request(
            'GET',
            '/' + Git.database_name + '/' + hash + '/checkout/',
            { },
            function (data) {
                if (data.status == 1) {
                    $(".commit").removeClass("commit-active");
                    $("#commit-" + hash).addClass("commit-active");
                    Git.clearDiff();
                    data.commit_hash = hash;
                    $("#diff").html(Mustache.render(Git.diff_template, data));
                    Git.last_hash = hash;
                } else {
                    Messager.alert(0, data.message);
                }
            }
        );
    },

    clearDiff: function() {
        $("#diff").children().remove();
    },

    clearCommits: function() {
        $("#commits").children().remove();
    },

    toggleSchema: function(schema) {
        if (Git.schemas_states[schema] == undefined) {
            Git.schemas_states[schema] = true;
        }
        Git.schemas_states[schema] = ! Git.schemas_states[schema];
        $(".s-" + schema).prop('checked', Git.schemas_states[schema]);
    },

    toggleSchemaObject: function(schema, object_index) {
        if (Git.schemas_objects_states[schema] == undefined) {
            Git.schemas_objects_states[schema] = {};
        }
        if (Git.schemas_objects_states[schema][object_index] == undefined) {
            Git.schemas_objects_states[schema][object_index] = true;
        }
        Git.schemas_objects_states[schema][object_index] = ! Git.schemas_objects_states[schema][object_index];
        $(".s-" + schema).filter(".o-" + object_index).prop('checked', Git.schemas_objects_states[schema][object_index]);
    },

    apply: function() {
        var objects = [];

        $(".apply").filter(":checked").each(function(n, e) {
            objects.push(e.name);
        });

        Git.request(
            'POST',
            '/' + Git.database_name + '/apply/',
            {
                objects: objects
            },
            function (data) {
                Messager.alert(data.status, data.status == 1 ? 'Applied' : data.message);
                Git.checkout(Git.last_hash);
            }
        );
    },

    getCommits: function() {
        Git.request(
            'GET',
            '/' + Git.database_name + '/get_commits/',
            { },
            function (data) {
                if (data.status == 1) {
                    Git.clearCommits();
                    $("#commits").html(Mustache.render(Git.commits_template, data));
                    Git.checkout(data['current_commit_hash']);
                } else {
                    Messager.alert(0, data['message']);
                }
            }
        );
    },

    changeDatabase: function() {
        Git.last_hash = '';
        Git.schemas_states = [];
        Git.schemas_objects_states = [];
    }

  }

