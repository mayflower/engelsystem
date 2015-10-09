/// <reference path='../_all.ts' />
var angularShift;
(function (angularShift) {
    var shifts;
    (function (shifts) {
        var ShiftEventConverter = (function () {
            function ShiftEventConverter() {
            }
            ShiftEventConverter.prototype.toEvent = function (shift) {
                var startDate = new Date();
                // startDate.setTime(shift.start * 1000);
                startDate.setMilliseconds(startDate.getMilliseconds() + 60 * 60 * 1000 * 1);
                var endDate = new Date();
                endDate.setMilliseconds(endDate.getMilliseconds() + 60 * 60 * 1000 * 3);
                // endDate.setTime(shift.end * 1000);
                return {
                    title: !_.isUndefined(shift.title) ? shift.title + '(' + shift.shiftType.name + ')' : shift.shiftType.name,
                    start: startDate,
                    end: endDate,
                    shiftValues: shift
                };
            };
            return ShiftEventConverter;
        })();
        shifts.ShiftEventConverter = ShiftEventConverter;
        angular.module('angularShift.shifts').service('ShiftsEventConverterService', ShiftEventConverter);
    })(shifts = angularShift.shifts || (angularShift.shifts = {}));
})(angularShift || (angularShift = {}));
