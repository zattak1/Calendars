(function (Q, $, window, undefined) {

var Users = Q.Users;
var Streams = Q.Streams;
var Calendars = Q.Calendars;

/**
 * This tool lets the user plan a new event
 * @class Calendars/event/composer
 * @constructor
 * @param {Object} [options] this is an object that contains parameters for this function
 *   @param {String} [options.communityId=Q.Users.communityId] The id of the user representing the community publishing the interests
 *   @param {String} options.publisherId The publisher id for the event stream
 *   @param {Objects} options.interests Override any options for Streams/interests tool
 *   @param {Q.Event} [options.onCreate] This event fires when the tool succesfully creates a new event
 *   @param {Function} [options.setLocation] Function to obtain the user's location,
 *     defaults to showing a dialog with Places/user/location tool.
 */
Q.Tool.define("Calendars/event/composer", function(options) {
	var tool = this;

	// set default value
	tool.communityId = options.communityId || Users.communityId;

	Q.addScript([
		'{{Q}}/pickadate/picker.js',
		'{{Q}}/pickadate/picker.date.js'
	], function () {
		var limit = 5000;
		var count = 0;
		var step = 500;
		var waitingPickadate = setInterval(function () {
			count += step;

			if (count >= limit) {
				console.warn("Calendars/event/composer: can't load pickadate jquery lib");
				clearInterval(waitingPickadate);
				return;
			}

			if (Q.typeOf($().pickadate) !== 'function') {
				return;
			}

			clearInterval(waitingPickadate);

			tool.refresh();
		}, step);

	});
},

{
	publisherId: Users.loggedInUserId(),
	publishers: [],
	onCreate: new Q.Event(),
	setLocation: function () {
		var tool = this;
		var title = Q.getObject(['composer', 'location', 'Title'], tool.text);
		var element = Q.Tool.setUpElement('div', 'Places/user/location');
		Q.Dialogs.push({
			title: title || 'Set Your Location',
			content: element,
			onActivate: function (dialog) {
				var $element = $(dialog).find('.Places_user_location_set');
				Q.Pointer.hint($element, { show: { delay: 1000 } });
				Q.Tool.from(element).state.onSet.set(function () {
					tool.$location.attr('data-locationDefined', true);
					Q.Dialogs.pop();
				}, tool);
			}
		});
	},
	choosePublisher: function (container) {
		var tool = this;
		var state = tool.state;
		var $button = $("button.Calendars_event_composer_publisher_button", tool.element);

		Q.Dialogs.push({
			title: Q.getObject(['event', 'composer', 'ChoosePublisher'], tool.text) || "Choose Publisher",
			className: 'Calendars_composer_publisher',
			content: $('<div>').tool('Users/list', {
				userIds: state.publishers,
				avatar: {icon: 80},
				clickable: true,
				onLoadMore: function (avatars) {
					Q.each(avatars, function () {
						$(this.element).on(Q.Pointer.fastclick, function (event) {
							event.stopPropagation();
							event.preventDefault();

							var avatarTool = Q.Tool.from(this);
							if (Q.typeOf(avatarTool) !== 'Q.Tool') {
								return console.error("element is not a valid Users/avatar tool");
							}

							state.publisherId = Q.getObject("state.userId", avatarTool);

							// if state.publisherId is community id
							if (state.publisherId[0] === state.publisherId[0].toUpperCase()) {
								tool.communityId = state.publisherId;
							}

							// set button texcontent to selected Users/avatar
							$("input.Calendars_composer_step", tool.$publisher).val(state.publisherId);
							$button.html($(avatarTool.element).clone());

							// set interests button to original condition
							$("button.Calendars_event_composer_interest_button", tool.$interest).text(Q.getObject(['event', 'composer', 'ChooseActivity'], tool.text));
							tool.$interest.val("");

							// read labels of new publisher and fill select element
							Q.req("Calendars/event", ["labels"], function (err, response) {
								var msg;
								if (msg = Q.firstErrorMessage(err, response && response.errors)) {
									throw new Q.Error(msg);
								}

								var labels = response.slots.labels;

								// remove old labels
								$("option", tool.$labels).remove();

								// fill with new labels
								for (var label in labels) {
									tool.$labels.append($("<option>").attr("value", label).text(labels[label]));
								}
							}, {
								method: "GET",
								fields: {
									userId: state.publisherId
								}
							});

							tool.prepareSteps();

							Q.Dialogs.pop();
							return false;
						});
					});
				}
			})
		});
	},
	chooseInterest: function (button) {
		var container = $(button).closest(".Calendars_event_composer_interest");
		var tool = this;
		var state = tool.state;
		var $container = $(container);
		var title = Q.getObject(['composer', 'interest', 'Title'], tool.text);
		var o = Q.extend({
			communityId: tool.communityId,
			//userId: state.publisherId,
			filter: tool.text.event.composer.interest.Filter,
			onClick: function (element, normalized, category, interest, wasSelected) {
				tool.category = category;
				tool.interest = interest;
				tool.interestTitle = category + ': ' + interest;
				if (!Q.getObject(
					[Users.communityId, category, interest],
					Q.Streams.Interests.all
				)) {
					// add it in the background, and hope it completes on time
					Q.Streams.Interests.add(tool.interestTitle, null, { publisherId: Users.communityId});
				}
				tool.$('.Calendars_event_composer_interest input.Calendars_composer_step').val(tool.interestTitle);
				$container.find('.Calendars_event_composer_interest_button').text(tool.interestTitle);
				$(element).addClass('Q_selected');
				Q.Dialogs.pop();
				_showLocations.call(tool);
				tool.prepareSteps();
				return false;
			}
		}, state.interests);
		Q.Dialogs.push({
			title: title || "Choose an Activity",
			className: 'Streams_dialog_interests',
			stylesheet: '{{Q}}/css/tools/expandable.css',
			content: Q.Tool.setUpElement('div', 'Streams/interests', o)
		});
	}
},

{
	refresh: function () {
		var tool = this;
		var $toolElement = $(this.element);
		var state = tool.state;
		var _getTimezoneOffset = function () {
			// Date#getTimezoneOffset() returns minutes *behind* UTC, so a zone
			// at or east of Greenwich is <= 0 and has to render as "GMT+…".
			// Testing "offset < 0" sent UTC itself — offset exactly 0 — to
			// "GMT-0", which is not one of the options in the composer template
			// (that list runs GMT-12 … GMT+0 … GMT+12). jQuery .val() with a
			// value no option carries *deselects* the select, so it ended up
			// with selectedIndex -1 and .val() === null, and the submit handler
			// below then overwrote the perfectly good IANA name from
			// Q.Intl.calendar() with that null. The server received no
			// timezoneName at all and constructed DateTimeZone(""), which is
			// where the request died. Only a browser actually running in UTC
			// could hit it, which is why it showed up first in CI.
			var offset = new Date().getTimezoneOffset();
			var sign = offset <= 0 ? '+' : '-';
			offset = Math.abs(offset);
			return "GMT" + sign + Math.round(offset/60);
		};

		// listen for Places/location tool activated and assign onChoose event
		tool.element.forEachTool("Places/location", function () {
			this.state.onChoose.set(function (location) {
				var val = !!location || '';
				tool.$locationStep && tool.$locationStep.val(val);
				tool.prepareSteps();
				$(tool.element).attr("data-locationDefined", val);
			}, tool);
		});

		// check if composer filled, if no - render template and call refresh again
		if (!tool.element.innerHTML) {
			Q.Template.render('Calendars/templates/event/composer', {
				peopleMin: Q.getObject('Calendars.Event.defaults.peopleMin') || 2,
				peopleMax: Q.getObject('Calendars.Event.defaults.peopleMax') || 10,
				payment: Q.getObject("Event.defaults.payment.currency", Calendars) || 'credits'
			}, function (err, html) {
				Q.replace(tool.element, html);;
				tool.refresh();
			});

			return;
		}

		tool.$composer = tool.$('.Calendars_event_composer').plugin('Q/placeholders');
		tool.$composer.children().not(':first-child').css({
			'opacity': 0.2,
			'pointer-events': 'none'
		});
		tool.$publisher = tool.$('.Calendars_event_composer_publisher');
		tool.$interest = tool.$('.Calendars_event_composer_interest');
		tool.$eventType = tool.$('.Calendars_event_composer_type select');
		tool.$location = tool.$('.Calendars_event_composer_location');
		tool.$locationStep = $('.Calendars_composer_step', tool.$location);
		tool.$livestream = tool.$('.Calendars_event_composer_livestream');
		tool.$eventUrl = tool.$('.Calendars_event_composer_link');
		tool.$address = tool.$('.Places_address_tool');
		tool.$results = tool.$('.Q_filter_results', tool.$address);
		tool.$time = tool.$('.Calendars_event_composer_time');
		tool.$duration = tool.$('.Calendars_event_composer_duration_container');
		tool.$date = tool.$('.Calendars_event_composer_date');
		tool.$privacy = tool.$('.Calendars_event_composer_privacy');
		tool.$labels = tool.$('.Calendars_event_composer_labels');
		tool.$title = tool.$('.Calendars_event_composer_title');
		tool.$payment = tool.$('.Calendars_event_composer_payment');
		tool.$paymentStep = $('input.Calendars_composer_step', tool.$payment);
		tool.$share = tool.$('.Q_buttons.Calendars_step');
		tool.$timezoneName = tool.$('select[name=timezoneName]');
		if (tool.$timezoneName.length) {
			tool.$timezoneName.val(_getTimezoneOffset());
			if (tool.$timezoneName.val() == null) {
				// The zone is outside the option list, or a half-hour zone
				// rounded out of it. Leave a real selection rather than an
				// empty one — anything the user does not touch still has to
				// submit a value the server can parse.
				tool.$timezoneName.val('GMT+0');
			}
		}

		var paymentTool = null;
		tool.element.forEachTool("Calendars/payment", function () {
			paymentTool = this;
			tool.$paymentStep.val(paymentTool.isValid() ? true : '');
			paymentTool.state.onError.set(function () {
				tool.$paymentStep.val('');
				tool.prepareSteps();
			});
			paymentTool.state.onComplete.set(function () {
				tool.$paymentStep.val(true);
				tool.prepareSteps();
			});
		});

		$("button.Calendars_event_composer_publisher_button", tool.$publisher).plugin('Q/clickable', {
			press: {size: 1.2},
			release: {size: 1.2}
		}).on(Q.Pointer.fastclick, function () {
			Q.handle(state.choosePublisher, tool, [this]);
		});

		var $livestreamUrl = $("input[name=livestream]", tool.$livestream);
		var $scheduleOnlineConference = $("button[name=scheduleOnlineConference]", tool.$livestream);
		var $livestreamStep = $("input.Calendars_composer_step", tool.$livestream);
		$livestreamStep.on("change", function () {
			$toolElement.attr("data-online", $livestreamStep.val());
		});
		// on change $livestream, call prepareSteps
		$livestreamUrl.on('change input', function () {
			var val = $(this).val();
			var $placeHolder = $livestreamUrl.closest(".Q_placeholders_container");

			if (val.matchTypes('url').length) {
				$livestreamStep.val('online').trigger("change");
				$scheduleOnlineConference.removeClass("Q_selected");
				$placeHolder.addClass("Q_selected");
			} else {
				$livestreamStep.val('').trigger("change");
				$placeHolder.removeClass("Q_selected");
			}

			tool.prepareSteps();
		});

		// Set Schedule Conference
		$scheduleOnlineConference.on(Q.Pointer.fastclick, function () {
			if ($scheduleOnlineConference.hasClass("Q_selected")) {
				$scheduleOnlineConference.removeClass("Q_selected");
				$livestreamStep.val('').trigger("change");
			} else {
				$scheduleOnlineConference.addClass("Q_selected");
				$livestreamUrl.val('');
				$livestreamUrl.closest(".Q_placeholders_container").removeClass("Q_selected");
				$livestreamStep.val('online').trigger("change");
			}

			tool.prepareSteps();
		});

		var $interestsButton =  tool.$('.Calendars_event_composer_interest_button');
		$interestsButton.plugin('Q/clickable', {
			press: {size: 1.2},
			release: {size: 1.2}
		}).on(Q.Pointer.fastclick, function () {
			Q.handle(state.chooseInterest, tool, [this]);
		});

		// event types
		var eventTypes = Q.getObject("Q.plugins.Calendars.events.types");
		var $eventTypes = tool.$eventType.closest('.Calendars_step');
		if (Q.isArrayLike(eventTypes)) {
			$eventTypes.show();
			var text = Q.getObject('event.composer.eventType', tool.text);
			if (!text) {
				console.warn("Calendars/event/composer: text event.composer.eventType absent!");
				text = "Event Type";
			}
			tool.$eventType.append('<option value="">' + text + '</option>');

			Q.Text.get(Q.info.app + '/content', function (err, content) {
				var texts = Q.getObject('Calendars.events.types', content);
				var eventType;

				if (!texts) {
					console.warn("Calendars/event/composer: text Calendars.events.types absent!");
				}

				for (var i = 0; i < eventTypes.length; i++) {
					eventType = eventTypes[i];
					tool.$eventType.append('<option value="' + eventType + '">' + (Q.getObject([eventType], texts) || eventType) + '</option>');
				}
			});

			// call prepareSteps when event type selected
			tool.$eventType.on('change', tool.prepareSteps.bind(tool));
		}

		tool.$('.Calendars_minmax').click(function () {
			var inputEl = this;
			var selStart = 0, selEnd = this.value.length;
			if ('setSelectionRange' in inputEl) {
				inputEl.focus();
				inputEl.setSelectionRange(selStart, selEnd);
			} else if (inputEl.createTextRange) {
				var range = inputEl.createTextRange();
				range.collapse(true);
				range.moveEnd('character', selEnd);
				range.moveStart('character', selStart);
				range.select();
			}
		});

		tool.$('.Calendars_event_composer_share').plugin('Q/clickable', {
			press: {size: 1.2},
			release: {size: 1.2}
		}).on(Q.Pointer.click, function () {
			if (!Q.Users.loggedInUser) {
				alert('Please log in first');
				return;
			}
			var day = tool.$date.nextAll('input[name=date]').val().split('/');
			var time = tool.$time.val().split(':');
			var date = new Date(
				day[0], day[1]-1, day[2],
				time[0], time[1]
			);
			var localStartDateTime = day.join('-') + ' ' + time.join(':');

			var duration = $("select[name=duration_hours]", tool.$duration).val() + ":" + $("select[name=duration_minutes]", tool.$duration).val();
			duration = duration === ':' ? null : duration;

			var labels = tool.$labels.val();
			if (labels === 'Calendars/*') {
				labels = '';
			}

			var recurring = tool.child('Calendars_recurring');

			var addressTool, locationTool, areasTool;
			if (!state.placeId) {
				if (addressTool = tool.child('Places_address')) {
					state.placeId = addressTool.place.id;
				} else if (locationTool = tool.child('Places_location')) {
					var str = Q.getObject('state.location.placeId', locationTool);
					state.placeId = (str || '').split('/').pop();

					areasTool = locationTool.$(".Places_areas_tool")[0];
					areasTool = areasTool ? Q.Tool.from(areasTool, "Places/areas") : null;
					if (areasTool && areasTool.state.areaSelected) {
						state.areaSelected = JSON.stringify(areasTool.state.areaSelected);
					}
				}
			}
			var eventTitle = $("input", tool.$title).val();
			var eventType = tool.$eventType instanceof jQuery ? tool.$eventType.val() : null;
			var livestream = $("input.Calendars_composer_step", tool.$livestream).val() || null;
			var eventUrl = $("input[name=eventUrl]", tool.$eventUrl).val() || null;
			var ticketsUrl = $("input[name=ticketsUrl]", tool.$eventUrl).val() || null;
			var fields = {
				communityId: tool.communityId,
				publisherId: state.publisherId,
				interestTitle: tool.interestTitle,
				eventType: eventType,
				eventTitle: eventTitle,
				placeId: state.placeId,
				livestream: livestream,
				eventUrl: eventUrl,
				ticketsUrl: ticketsUrl,
				areaSelected: state.areaSelected || null,
				localStartDateTime: localStartDateTime,
				duration: duration,
				timezone: date.getTimezoneOffset(),
				labels: labels,
				recurring: JSON.stringify({
					period: recurring.state.period,
					days: recurring.state.days
				}),
				payment: paymentTool && paymentTool.getValue(),
				peopleMin: tool.$('input[name=peopleMin]').val(),
				peopleMax: tool.$('input[name=peopleMax]').val()
			};

			// set local time zone
			var intl = Q.Intl.calendar();
			if (intl.timeZone) {
				fields.timezoneName = intl.timeZone;
			}

			// set diff time zone if defined.
			// The truthiness test is load-bearing: a select with nothing
			// selected answers null, and assigning that unconditionally threw
			// away the IANA name set just above. Only an actual choice should
			// override the browser's own zone.
			var timezoneNameSelected = tool.$timezoneName.length
				&& tool.$timezoneName.is(":visible")
				&& tool.$timezoneName.val();
			if (timezoneNameSelected) {
				fields.timezoneName = timezoneNameSelected;
			}

			var $this = $(this);
			$this.addClass('Q_working').attr('disabled', 'disabled');
			Q.req('Calendars/event', ['stream'], function (err, data) {
				var msg = Q.firstErrorMessage(
					err, data && data.errors
				);
				if (msg) {
					$this.removeClass('Q_working').removeAttr('disabled');
					return alert(msg);
				}
				var stream = Q.Streams.Stream.construct(data.slots.stream, null, null, true);
				Q.handle(state.onCreate, tool, [stream]);
			}, {
				method: 'post',
				fields: fields
			});

		})[0].preventSelections();

		var date = new Date( Date.now() + 1000 * 60 * 60 * 24 );
		var y = date.getFullYear();
		var m = date.getMonth();
		var d = date.getDate();
		state.weekdays = [];
		var weekKeys = Object.keys(tool.text.weekdays);
		Q.each(weekKeys, function (i, weekday) {
			state.weekdays.push(tool.text.weekdays[weekday]);
		});

		tool.$date.pickadate({
			showMonthsShort: true,
			format: 'ddd, mmm d, yyyy',
			formatSubmit: 'yyyy/mm/dd',
			hiddenName: true,
			min: new Date(),
			container: 'body',
			onStart: function () {
				this.set('select', new Date(y, m, d));
			}
		}).on('change', function () {
			_hideEarlierTimes(tool.$date, tool.$time);
		});
		_hideEarlierTimes(tool.$date, tool.$time);

		tool.$date.add(tool.$time).on('change input', tool.prepareSteps.bind(tool));

		// Set My Location button
		Streams.retainWith(true).get(Users.loggedInUser.id, "Places/user/location", function (err) {
			if (!err && this.getAttribute('latitude') && this.getAttribute('longitude')) {
				tool.$location.attr('data-locationDefined', true);
				return;
			}

			var stream = this;
			tool.$location.attr('data-locationDefined', false);
			tool.$('.Calendars_event_composer_location_button').plugin('Q/clickable', {
				className: 'Calendars_event_composer_location_button',
				press: {size: 1.2},
				release: {size: 1.2}
			}).on(Q.Pointer.fastclick, function () {
				Q.handle(state.setLocation, tool);
			});

			this.onMessage('Places/location/updated').set(function (message) {
				var filter = tool.child('Places_address_Q_filter');
				if (filter) {
					filter.$input.plugin('Q/placeholders');
				}
				var attributes = stream.getAllAttributes();
				if (attributes.latitude && attributes.longitude) {
					_showLocations.call(tool);
					tool.$location.removeClass('Calendars_location_unset').addClass('Calendars_location_set');
					Q.Pointer.hint(tool.$location);
				}
			}, tool);
		});

		function _hideEarlierTimes($date, $time) {
			var day = $date.nextAll('input[name=date]')
				.val().split('/');
			var now = new Date();
			$time.find('option').show();
			var shouldHide = true;
			if (now.getFullYear() !== parseInt(day[0])
				|| now.getMonth() !== parseInt(day[1])-1
				|| now.getDate() !== parseInt(day[2])) {
				shouldHide = false;
			}
			var now = new Date();
			var hours = now.getHours();
			var minutes = now.getMinutes();
			var $selected;
			$time.find('option').each(function () {
				var $option = $(this);
				var parts = $option.attr('value').split(':');
				if (parts[0] < hours
				|| (parts[0] == hours && parts[1] <= minutes)) {
					if (shouldHide) {
						$option.hide();
					}
				} else if (!$selected) {
					$time.val($option.attr('value'));
					$selected = $option;
				}
			});
		}
	},
	prepareSteps: function () {
		var tool = this;
		var steps = [];
		// create steps list
		Q.each([
			[tool.$publisher],
			[tool.$interest],
			[tool.$eventType],
			[tool.$location, 'or', tool.$livestream],
			[tool.$eventUrl],
			[tool.$date, 'and', tool.$time],
			[tool.$labels],
			[tool.$payment],
			[tool.$share]
		],
		function (i, step) {
			// skip invisible steps
			Q.each(step, function (i, item) {
				if (item === true || item instanceof jQuery && !item.is(":visible")) {
					step.splice(i, 1);
				}
			});

			!Q.isEmpty(step) && steps.push(step);
		});

		var globalFilledOut = true;
		Q.each(steps, function (i, step) {
			var sign = null;
			var filledOut = true;
			Q.each(step, function (i, item) {
				if (item === 'and' || item === 'or') {
					sign = item;
					return;
				}

				// if OR comparison and prev item filledOut, don't check other items, all of them filledOut
				if (sign === 'or' && filledOut) {
					return;
				}

				if (item === true) {
					// if OR comparison and current item filledOut, set global filledOut to true
					if (sign === 'or') {
						filledOut = true;
					}
					return;
				}

				if (item === false) {
					// if OR comparison and current item not filledOut, skip modification of global filledOut
					if (sign === 'or') {
						return;
					}
					filledOut = false;
					return;
				}

				if (jQuery.inArray(item.prop('tagName').toLowerCase(), ['input', 'select']) === -1) {
					item = $("input.Calendars_composer_step", item);
				}

				var val = item.val();
				if (item.attr('data-type')) {
					switch (item.attr('data-type')) {
						case 'url':
							filledOut = val.matchTypes('url').length;
							break;
					}
				} else {
					filledOut = !!val;
				}
			});

			if (!filledOut) {
				globalFilledOut = false;
			}

			var nextStep = steps[i+1];
			if (!nextStep) {
				return;
			}

			Q.each(nextStep, function (j, item) {
				if (!(item instanceof jQuery)) {
					return;
				}

				if (!item.hasClass('Calendars_step')) {
					item = item.closest(".Calendars_step");
					if (!item.length) {
						return;
					}
				}

				if (globalFilledOut) {
					item.css({'pointer-events': 'auto'}).stop().animate({'opacity': 1});
				} else {
					item.css({'pointer-events': 'none'}).stop().animate({'opacity': 0.5});
					return false;
				}
			});
		});
	}
});

function _showLocations () {
	var tool = this;
	var locationTool = tool.child('Places_location');

	if (!locationTool) {
		return;
	}

	var as = locationTool.addressTool.state;
	tool.$address.addClass('Q_throb');
	Streams.retainWith(true).get(
		Users.loggedInUser.id, "Places/user/location",
		function (err) {
			if (err) {
				return;
			}
			as.meters = this.getAttribute('meters');
			as.latitude = this.getAttribute('latitude');
			as.longitude = this.getAttribute('longitude');

			var src = Q.url('action.php/Streams/interests', {communityId: Users.communityId});
			var siCallback = function (searchQuery) {
				as.searchQuery = searchQuery;
				locationTool.addressTool.refresh(function () {
					tool.$address.removeClass('Q_throb');
				});
			};
			Q.addScript(src, function () {
				var all = Q.Streams.Interests.all[tool.communityId];
				for (var k in all[tool.category]) {
					var info = Q.getObject([tool.category, k, tool.interest], all);
					if (!info) {
						continue;
					}
					var searchQuery = info.q || (tool.interest + " " + tool.category);
					siCallback(searchQuery);
					return;
				}
				// we didn't find a search query, so just pick one for
				searchQuery = tool.interest + " " + tool.category;
				siCallback(searchQuery);
			});
		}
	);
}

Q.Template.set('Calendars/event/composer/SOC',
	'<div>{{text.SOCDescription}}</div>' +
	'<div class="Calendars_event_composer_date_container">' +
	'	<label for="Calendars_event_composer_date">{{text.Day}}:</label>' +
	'	<input name="date" class="Calendars_event_composer_date">' +
	'</div>' +
	'<div class="Calendars_event_composer_time_container">' +
	'	<label for="Calendars_event_composer_time">{{text.Starting}}:</label>' +
	'	{{{startTime}}}' +
	'</div>' +
	'<div class="Calendars_event_composer_time_container">' +
	'	<label for="Calendars_event_composer_time">{{text.Ending}}:</label>' +
	'	{{{endTime}}}' +
	'</div>' +
	'<button class="Q_button" name="SOC_save">{{text.Share}}</button>'
);

})(Q, Q.jQuery, window);