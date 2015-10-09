/// <reference path='../_all.ts' />

module angularShift.shifts {
    export class ShiftsOnCalendarController {
        private date: Date;
        private d: number;
        private m: number;
        private y: number;
        private events: Array<FullCalendar.EventObject> = [];
        private uiCalendarConfig: FullCalendar.Options;
        private eventSources: Array<FullCalendar.EventSource>;
        private shiftsService: angularShift.shiftEntries.ShiftEntriesService;
        private converter: ShiftEventConverter;
        private $state;

        constructor(uiCalendarConfig, $scope, shiftsService: angularShift.shiftEntries.ShiftEntriesService, converter: ShiftEventConverter, $state) {
            this.date = new Date();
            this.d = this.date.getDate();
            this.m = this.date.getMonth();
            this.y = this.date.getFullYear();

            this.uiCalendarConfig = uiCalendarConfig;
            this.shiftsService = shiftsService;
            this.converter = converter;
            this.eventSources = [];
            this.$state = $state;

            $scope.uiConfig = {
                calendar:{
                    height: 450,
                    editable: true,
                    header:{
                        left: 'month agendaWeek agendaDay',
                        center: 'title',
                        right: 'today prev,next'
                    },
                    eventClick: (event) => {
                        this.$state.go('shifts.show', {id: event.shiftValues.SID});
                    },
                    eventDrop: $scope.alertOnDrop,
                    eventResize: $scope.alertOnResize
                }
            };

            this.init();
        }

        init () {
            this.eventSources = [this.events];
            this.shiftsService.getAll().then((data) => {
                _.each(data, (shift: ShiftInterface) => {
                    this.events.push(this.converter.toEvent(shift))
                });
            });
        }

    }

    ShiftsOnCalendarController.$inject = [
        'uiCalendarConfig',
        '$scope',
        'ShiftsService',
        'ShiftsEventConverterService',
        '$state'
    ];

    angular.module('angularShift.shifts').controller('ShiftsOnCalendarController', ShiftsOnCalendarController);
}
